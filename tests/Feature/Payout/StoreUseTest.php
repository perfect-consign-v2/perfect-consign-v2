<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use InvalidArgumentException;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Feature\Payout\StoreUse;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class StoreUseTest extends TestCase
{
    public function testTheSameFormPostsOnce(): void
    {
        $token = '0123456789abcdef0123456789abcdef';
        $first = StoreUse::postingKey('01900000-0000-7000-8000-000000000e01', 'Ada-1', 1250, $token);
        $again = StoreUse::postingKey('01900000-0000-7000-8000-000000000e01', 'ada-1', 1250, $token);

        self::assertSame($first, $again);
        self::assertNotSame(
            $first,
            StoreUse::postingKey('01900000-0000-7000-8000-000000000e01', 'Ada-1', 1251, $token)
        );
        self::assertNotSame(
            $first,
            StoreUse::postingKey('01900000-0000-7000-8000-000000000e01', 'Ada-1', 1250, 'fedcba9876543210fedcba9876543210')
        );
    }

    public function testAMissingTokenIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StoreUse::postingKey('01900000-0000-7000-8000-000000000e01', 'Ada-1', 1250, '');
    }

    public function testRecordRefusesANamelessOrNonPositiveUse(): void
    {
        $use = $this->use($this->createStub(Statements::class), $this->createStub(LedgerStore::class));
        $cases = [
            ['', 'for the office', 1250, 'a store use names the piece'],
            ['Lamp', '   ', 1250, 'a store use names the piece'],
            ['Lamp', 'for the office', 0, 'a store use is a positive amount'],
            ['Lamp', 'for the office', -1, 'a store use is a positive amount'],
        ];
        foreach ($cases as [$description, $memo, $amount, $message]) {
            try {
                $use->record(self::VENDOR, 'Ada-1', $amount, '2026-06-15', $description, $memo, self::TOKEN);
                self::fail($message);
            } catch (InvalidArgumentException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }

    public function testAStoreUseNeedsOneItem(): void
    {
        $blank = $this->createMock(Statements::class);
        $blank->expects(self::never())->method('bound');
        try {
            $this->use($blank, $this->createStub(LedgerStore::class))->record(
                self::VENDOR,
                '   ',
                1250,
                '2026-06-15',
                'Lamp',
                'for the office',
                self::TOKEN,
            );
            self::fail('a store use needs an item');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a store use needs an item', $error->getMessage());
        }
        foreach ([[], [['id' => self::ITEM], ['id' => self::ITEM]]] as $rows) {
            $statements = $this->createStub(Statements::class);
            $statements->method('bound')->willReturn($rows);
            try {
                $this->use($statements, $this->createStub(LedgerStore::class))->record(
                    self::VENDOR,
                    'Ada-1',
                    1250,
                    '2026-06-15',
                    'Lamp',
                    'for the office',
                    self::TOKEN,
                );
                self::fail('a store use needs an item');
            } catch (DatabaseRefusal $error) {
                self::assertSame('a store use needs an item', $error->getMessage());
            }
        }
    }

    public function testAStoreUseRefusesAnExpiredForm(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['id' => self::ITEM]]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('this form has expired');
        $this->use($statements, $this->createStub(LedgerStore::class))->record(
            self::VENDOR,
            'Ada-1',
            1250,
            '2026-06-15',
            'Lamp',
            'for the office',
            'short',
        );
    }

    public function testAStoreUsePostsTheAgreedPrice(): void
    {
        $statements = $this->createMock(Statements::class);
        $statements->method('bound')->willReturn([['id' => self::ITEM]]);
        $statements->expects(self::once())->method('change')->with(
            self::callback(static fn (string $sql): bool => str_contains($sql, 'INSERT INTO store_uses')),
            [self::JOURNAL, self::VENDOR, self::ITEM, 1250, 'Lamp', 'for the office'],
        );
        $audit = $this->createMock(Statements::class);
        $audit->expects(self::once())->method('change')->with(
            self::callback(static fn (string $sql): bool => str_contains($sql, 'audit_events')),
            self::callback(static function (array $params): bool {
                return $params[0] === 'Ada'
                    && $params[1] === 'owner'
                    && $params[2] === 'store_use.record'
                    && $params[3] === 'journal'
                    && $params[4] === self::JOURNAL;
            }),
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
        $journalId = $this->use($statements, $ledger, $audit)->record(
            self::VENDOR,
            '  AbC  ',
            1250,
            '2026-06-15',
            ' Lamp ',
            ' for the office ',
            self::TOKEN,
        );

        self::assertSame(self::JOURNAL, $journalId);
        self::assertInstanceOf(JournalDraft::class, $posted);
        self::assertSame(StoreUse::postingKey(self::VENDOR, '  AbC  ', 1250, self::TOKEN), $posted->postingKey);
        self::assertSame('store_use', $posted->source->type);
        self::assertSame(substr($posted->postingKey, strlen('store-use:')), $posted->source->reference);
        self::assertSame('store_purchases', $posted->lines[0]['account_id']);
        self::assertSame(1250, $posted->lines[0]['debit']);
        self::assertSame(0, $posted->lines[0]['credit']);
        self::assertSame('vendor_payable', $posted->lines[1]['account_id']);
        self::assertSame(0, $posted->lines[1]['debit']);
        self::assertSame(1250, $posted->lines[1]['credit']);
        self::assertSame('vendor', $posted->lines[1]['subledger_type']);
        self::assertSame(self::VENDOR, $posted->lines[1]['subledger_ref']);
    }

    public function testAnItemRowWithoutAnIdIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([[]]);
        $this->expectException(InvalidArgumentException::class);
        $this->use($statements, $this->createStub(LedgerStore::class))->record(
            self::VENDOR,
            'Ada-1',
            1250,
            '2026-06-15',
            'Lamp',
            'for the office',
            self::TOKEN,
        );
    }

    public function testVendorsAreListed(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturn([
            ['id' => self::VENDOR, 'name' => 'Ada'],
        ]);

        self::assertSame(
            [['id' => self::VENDOR, 'name' => 'Ada']],
            $this->use($statements, $this->createStub(LedgerStore::class))->vendors(),
        );
        $empty = $this->createStub(Statements::class);
        $empty->method('rows')->willReturn([]);
        self::assertSame([], $this->use($empty, $this->createStub(LedgerStore::class))->vendors());
    }

    public function testAVendorRowWithoutANameIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturn([['id' => self::VENDOR]]);
        $this->expectException(InvalidArgumentException::class);
        $this->use($statements, $this->createStub(LedgerStore::class))->vendors();
    }

    private const VENDOR = '01900000-0000-7000-8000-0000000000v1';

    private const ITEM = '01900000-0000-7000-8000-0000000000i1';

    private const JOURNAL = '01900000-0000-7000-8000-0000000000j1';

    private const TOKEN = '0123456789abcdef0123456789abcdef';

    private function use(Statements $statements, LedgerStore $ledger, ?Statements $audit = null): StoreUse
    {
        $session = $this->createStub(Session::class);
        $session->method('get')->willReturnCallback(static fn (string $key): string => match ($key) {
            'owner_name' => 'Ada',
            'actor_role' => 'owner',
            default => '',
        });

        return new StoreUse(
            $ledger,
            $statements,
            new JournalAudit(new Actor($session), new AuditLog($audit ?? $this->createStub(Statements::class))),
        );
    }
}
