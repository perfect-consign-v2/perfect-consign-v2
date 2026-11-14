<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Book;

use InvalidArgumentException;
use PerfectConsign\Feature\Book\VendorMemo;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class VendorMemoTest extends TestCase
{
    private const VENDOR = '01900000-0000-7000-8000-0000000000v1';

    private const JOURNAL = '01900000-0000-7000-8000-0000000000j1';

    private const TOKEN = '0123456789abcdef0123456789abcdef';

    public function testOpenPayableRefusesAMissingVendor(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([]);
        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('a vendor memo needs a vendor');
        $this->memo($statements)->openPayable(self::VENDOR);
    }

    public function testOpenPayableReadsTheBalance(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnOnConsecutiveCalls(
            [['id' => self::VENDOR]],
            [['owed' => '1500']],
        );

        self::assertSame(1500, $this->memo($statements)->openPayable(self::VENDOR));
    }

    public function testOpenPayableRefusesAMissingBalance(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnOnConsecutiveCalls(
            [['id' => self::VENDOR]],
            [['owed' => null]],
        );
        $this->expectException(InvalidArgumentException::class);
        $this->memo($statements)->openPayable(self::VENDOR);
    }

    public function testRecordRefusesABadMemo(): void
    {
        $memo = $this->memo($this->createStub(Statements::class));
        $cases = [
            ['credit', 100, '   ', self::TOKEN, 'a vendor memo needs a reason'],
            ['sideways', 100, 'rent fix', self::TOKEN, 'a vendor memo credits or debits the vendor'],
            ['debit', 0, 'rent fix', self::TOKEN, 'a vendor memo is a positive amount'],
            ['credit', 100, 'rent fix', 'short', 'this form has expired'],
        ];
        foreach ($cases as [$direction, $amount, $reason, $token, $message]) {
            try {
                $memo->record(self::VENDOR, $direction, $amount, '2026-06-15', $reason, $token);
                self::fail($message);
            } catch (InvalidArgumentException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }

    public function testARepeatedMemoReturnsTheJournal(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['id' => self::JOURNAL]]);
        $ledger = $this->createMock(LedgerStore::class);
        $ledger->expects(self::never())->method('post');

        self::assertSame(self::JOURNAL, $this->memo($statements, $ledger)->record(
            self::VENDOR,
            'credit',
            100,
            '2026-06-15',
            'rent fix',
            self::TOKEN,
        ));
    }

    public function testARepeatedMemoWithoutAnIdIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([[]]);
        $this->expectException(InvalidArgumentException::class);
        $this->memo($statements)->record(self::VENDOR, 'credit', 100, '2026-06-15', 'rent fix', self::TOKEN);
    }

    public function testACreditMemoIncreasesWhatTheStoreOwes(): void
    {
        $posted = $this->post('credit');

        self::assertSame('vendor-memo:' . self::TOKEN, $posted->postingKey);
        self::assertSame('vendor_memo', $posted->source->type);
        self::assertSame(self::TOKEN, $posted->source->reference);
        self::assertSame('vendor_allowance', $posted->lines[0]['account_id']);
        self::assertSame(250, $posted->lines[0]['debit']);
        self::assertSame(0, $posted->lines[0]['credit']);
        self::assertNull($posted->lines[0]['subledger_type']);
        self::assertSame('vendor_payable', $posted->lines[1]['account_id']);
        self::assertSame(0, $posted->lines[1]['debit']);
        self::assertSame(250, $posted->lines[1]['credit']);
        self::assertSame(self::VENDOR, $posted->lines[1]['subledger_ref']);
    }

    public function testADebitMemoChargesTheVendor(): void
    {
        $posted = $this->post('debit');

        self::assertSame('vendor_payable', $posted->lines[0]['account_id']);
        self::assertSame(250, $posted->lines[0]['debit']);
        self::assertSame('vendor', $posted->lines[0]['subledger_type']);
        self::assertSame(self::VENDOR, $posted->lines[0]['subledger_ref']);
        self::assertSame('vendor_charge', $posted->lines[1]['account_id']);
        self::assertSame(250, $posted->lines[1]['credit']);
        self::assertSame(0, $posted->lines[1]['debit']);
        self::assertNull($posted->lines[1]['subledger_ref']);
    }

    public function testVendorsAreListed(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturn([
            ['id' => self::VENDOR, 'name' => 'Ada'],
        ]);

        self::assertSame(
            [['id' => self::VENDOR, 'name' => 'Ada']],
            $this->memo($statements)->vendors(),
        );
        $empty = $this->createStub(Statements::class);
        $empty->method('rows')->willReturn([]);
        self::assertSame([], $this->memo($empty)->vendors());
    }

    public function testAVendorRowWithoutANameIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturn([['id' => self::VENDOR]]);
        $this->expectException(InvalidArgumentException::class);
        $this->memo($statements)->vendors();
    }

    private function post(string $direction): JournalDraft
    {
        $statements = $this->createMock(Statements::class);
        $statements->method('bound')->willReturn([]);
        $statements->expects(self::once())->method('change')->with(
            self::callback(static fn (string $sql): bool => str_contains($sql, 'INSERT INTO vendor_memos')),
            [self::JOURNAL, self::VENDOR, $direction, 250, 'rent fix'],
        );
        $posted = null;
        $ledger = $this->createMock(LedgerStore::class);
        $ledger->method('roleAccount')->willReturnArgument(0);
        $ledger->expects(self::once())->method('post')->willReturnCallback(
            static function (JournalDraft $draft) use (&$posted): string {
                $posted = $draft;
                if ($draft->beforeCommit !== null) {
                    ($draft->beforeCommit)(self::JOURNAL);
                }

                return self::JOURNAL;
            },
        );
        $journalId = $this->memo($statements, $ledger)->record(
            self::VENDOR,
            $direction,
            250,
            '2026-06-15',
            ' rent fix ',
            self::TOKEN,
        );
        self::assertSame(self::JOURNAL, $journalId);
        self::assertInstanceOf(JournalDraft::class, $posted);

        return $posted;
    }

    private function memo(Statements $statements, ?LedgerStore $ledger = null): VendorMemo
    {
        return new VendorMemo($ledger ?? $this->createStub(LedgerStore::class), $statements);
    }
}
