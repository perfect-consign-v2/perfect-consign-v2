<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Book;

use InvalidArgumentException;
use PerfectConsign\Feature\Book\MissedSale;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class MissedSaleTest extends TestCase
{
    private const VENDOR = '01900000-0000-7000-8000-0000000000v1';

    private const ITEM = '01900000-0000-7000-8000-0000000000i1';

    private const JOURNAL = '01900000-0000-7000-8000-0000000000j1';

    private const TOKEN = '0123456789abcdef0123456789abcdef';

    public function testTheVendorShareUsesTheItemOrTheVendorRate(): void
    {
        $withItem = $this->sale($this->rates([['bps' => '4000', 'item_id' => self::ITEM]]));
        $withVendor = $this->sale($this->rates([['bps' => 0]]));

        self::assertSame(6000, $withItem->vendorShare(self::VENDOR, 'Ada-1', 10000));
        self::assertSame(10000, $withVendor->vendorShare(self::VENDOR, '  ', 10000));
    }

    public function testAMissingVendorOrItemIsRefused(): void
    {
        $sale = $this->sale($this->rates([]));
        try {
            $sale->vendorShare(self::VENDOR, '', 1000);
            self::fail('a missed sale needs a vendor');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a missed sale needs a vendor', $error->getMessage());
        }
        try {
            $sale->vendorShare(self::VENDOR, 'Ada-1', 1000);
            self::fail('a missed sale needs an item');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a missed sale needs an item', $error->getMessage());
        }
    }

    public function testARateWithoutBasisPointsIsRefused(): void
    {
        $sale = $this->sale($this->rates([['item_id' => self::ITEM]]));
        $this->expectException(InvalidArgumentException::class);
        $sale->vendorShare(self::VENDOR, 'Ada-1', 1000);
    }

    public function testRecordRefusesANamelessPieceOrABadToken(): void
    {
        $sale = $this->sale($this->createStub(Statements::class));
        $cases = [
            ['', 'walked out', self::TOKEN],
            ['Lamp', '   ', self::TOKEN],
            ['Lamp', 'walked out', 'short'],
        ];
        $expected = [
            'a missed sale names the piece',
            'a missed sale names the piece',
            'this form has expired',
        ];
        foreach ($cases as $index => [$description, $memo, $token]) {
            try {
                $sale->record(self::VENDOR, '', 1000, '2026-06-15', $description, $memo, $token);
                self::fail($expected[$index]);
            } catch (InvalidArgumentException $error) {
                self::assertSame($expected[$index], $error->getMessage());
            }
        }
    }

    public function testARepeatedMissedSaleReturnsTheJournal(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnOnConsecutiveCalls(
            [['bps' => '0']],
            [['id' => self::JOURNAL]],
        );
        $ledger = $this->createMock(LedgerStore::class);
        $ledger->expects(self::never())->method('post');

        self::assertSame(self::JOURNAL, $this->sale($statements, $ledger)->record(
            self::VENDOR,
            '',
            1000,
            '2026-06-15',
            'Lamp',
            'walked out',
            self::TOKEN,
        ));
    }

    public function testARepeatedMissedSaleWithoutAnIdIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnOnConsecutiveCalls(
            [['bps' => '0']],
            [[]],
        );
        $this->expectException(InvalidArgumentException::class);
        $this->sale($statements)->record(self::VENDOR, '', 1000, '2026-06-15', 'Lamp', 'walked out', self::TOKEN);
    }

    public function testAMissedSalePostsTheVendorShare(): void
    {
        $posted = $this->post('Ada-1', [['bps' => '4000', 'item_id' => self::ITEM]], self::ITEM);

        self::assertSame('missed-sale:' . self::TOKEN, $posted->postingKey);
        self::assertSame('missed_sale', $posted->source->type);
        self::assertSame(self::TOKEN, $posted->source->reference);
        self::assertSame(600, $posted->lines[0]['debit']);
        self::assertSame(0, $posted->lines[0]['credit']);
        self::assertSame('operating_expense', $posted->lines[0]['account_id']);
        self::assertSame(0, $posted->lines[1]['debit']);
        self::assertSame(600, $posted->lines[1]['credit']);
        self::assertSame('vendor', $posted->lines[1]['subledger_type']);
        self::assertSame(self::VENDOR, $posted->lines[1]['subledger_ref']);
    }

    public function testAMissedSaleWithoutAnItemPostsTheVendorRate(): void
    {
        $posted = $this->post('', [['bps' => '0']], null);

        self::assertSame(1000, $posted->lines[0]['debit']);
        self::assertNull($posted->lines[0]['subledger_type']);
        self::assertSame(1000, $posted->lines[1]['credit']);
    }

    public function testVendorsAreListed(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturn([
            ['id' => self::VENDOR, 'name' => 'Ada'],
        ]);

        self::assertSame(
            [['id' => self::VENDOR, 'name' => 'Ada']],
            $this->sale($statements)->vendors(),
        );
        self::assertSame([], $this->sale($this->rows([]))->vendors());
    }

    public function testAVendorRowWithoutANameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->sale($this->rows([['id' => self::VENDOR]]))->vendors();
    }

    private function post(string $itemCode, array $rate, ?string $itemId): JournalDraft
    {
        $statements = $this->createMock(Statements::class);
        $statements->method('bound')->willReturnOnConsecutiveCalls($rate, []);
        $statements->expects(self::once())->method('change')->with(
            self::callback(static fn (string $sql): bool => str_contains($sql, 'INSERT INTO missed_sales')),
            [self::JOURNAL, self::VENDOR, $itemId, 1000, 'Lamp', 'walked out'],
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
        $journalId = $this->sale($statements, $ledger)->record(
            self::VENDOR,
            $itemCode,
            1000,
            '2026-06-15',
            ' Lamp ',
            ' walked out ',
            self::TOKEN,
        );
        self::assertSame(self::JOURNAL, $journalId);
        self::assertInstanceOf(JournalDraft::class, $posted);

        return $posted;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function rates(array $rows): Statements
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn($rows);

        return $statements;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function rows(array $rows): Statements
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturn($rows);

        return $statements;
    }

    private function sale(Statements $statements, ?LedgerStore $ledger = null): MissedSale
    {
        return new MissedSale($ledger ?? $this->createStub(LedgerStore::class), $statements);
    }
}
