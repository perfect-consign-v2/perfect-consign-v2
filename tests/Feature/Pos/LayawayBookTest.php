<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Pos\LayawayBook;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class LayawayBookTest extends TestCase
{
    public function testABlankTicketHasNoBalanceOrPayments(): void
    {
        $book = new LayawayBook($this->createStub(Statements::class), $this->createStub(LedgerStore::class));

        self::assertSame(0, $book->balance(''));
        self::assertSame([], $book->payments(''));
        self::assertSame(['lines' => [], 'sum' => 0, 'check' => null, 'cash' => 0], $book->include('t', 0, [
            'lines' => [],
            'sum' => 0,
            'check' => null,
            'cash' => 0,
        ]));
    }

    public function testPaymentsNameUnknownTendersAndMarkRefunds(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([
            ['posted_on' => '2026-10-01', 'tender' => 'points', 'amount' => 50, 'source_type' => 'layaway_refund'],
            ['posted_on' => '2026-10-02', 'tender' => 'cash', 'amount' => 25, 'source_type' => 'layaway_deposit'],
        ]);
        $book = new LayawayBook($statements, $this->createStub(LedgerStore::class));

        self::assertSame([
            ['posted_on' => '2026-10-01', 'tender' => 'points', 'amount' => 50, 'kind' => 'Refund'],
            ['posted_on' => '2026-10-02', 'tender' => 'Cash', 'amount' => 25, 'kind' => 'Deposit'],
        ], $book->payments('ticket-1'));
    }

    public function testAHoldWithoutATicketIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['balance' => 0]]);
        $book = new LayawayBook($statements, $this->createStub(LedgerStore::class));
        try {
            $book->hold('sess', '2026-10-01', '', 100, [
                'lines' => [],
                'sum' => 10,
                'check' => null,
                'cash' => 10,
            ]);
            self::fail('a blank ticket was parked');
        } catch (DatabaseRefusal $error) {
            self::assertSame('there is no open ticket', $error->getMessage());
        }
    }

    public function testAHoldThatClearsTheBalanceIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnOnConsecutiveCalls(
            [['role' => 'cash']],
            [['balance' => 0]],
        );
        $book = new LayawayBook($statements, $this->createStub(LedgerStore::class));
        try {
            $book->hold('sess', '2026-10-01', 'ticket-1', 10, [
                'lines' => [['account_id' => 'a', 'debit' => 10, 'credit' => 0, 'subledger_type' => null, 'subledger_ref' => null]],
                'sum' => 10,
                'check' => null,
                'cash' => 10,
            ]);
            self::fail('a full payment was parked as a layaway');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a layaway deposit cannot pass the amount due', $error->getMessage());
        }
    }

    public function testAClosedTicketCannotBeParked(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnOnConsecutiveCalls(
            [['role' => 'cash']],
            [['balance' => 0]],
            [['balance' => 0]],
            [['taken' => 0]],
        );
        $statements->method('transaction')->willReturnCallback(static function (callable $work): mixed {
            $work();

            return null;
        });
        $statements->method('affected')->willReturn(0);
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn('acct-cash');
        $book = new LayawayBook($statements, $ledger);
        try {
            $book->hold('sess', '2026-10-01', 'ticket-1', 100, [
                'lines' => [
                    ['account_id' => 'acct-cash', 'debit' => 0, 'credit' => 5, 'subledger_type' => null, 'subledger_ref' => null],
                    ['account_id' => 'acct-cash', 'debit' => 10, 'credit' => 0, 'subledger_type' => null, 'subledger_ref' => null],
                ],
                'sum' => 10,
                'check' => null,
                'cash' => 10,
            ]);
            self::fail('a closed ticket was parked');
        } catch (DatabaseRefusal $error) {
            self::assertSame('there is no open ticket', $error->getMessage());
        }
    }

    public function testALayawayNeedsAKnownTenderRole(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnOnConsecutiveCalls(
            [['balance' => 0]],
            [['role' => 'points']],
        );
        $book = new LayawayBook($statements, $this->createStub(LedgerStore::class));
        try {
            $book->hold('sess', '2026-10-01', 'ticket-1', 100, [
                'lines' => [['account_id' => 'acct', 'debit' => 10, 'credit' => 0, 'subledger_type' => null, 'subledger_ref' => null]],
                'sum' => 10,
                'check' => null,
                'cash' => 10,
            ]);
            self::fail('a points tender was parked');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a layaway takes cash, card, or a check', $error->getMessage());
        }
    }

    public function testAMissingChartRoleIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnOnConsecutiveCalls(
            [['balance' => 0]],
            [[]],
        );
        $book = new LayawayBook($statements, $this->createStub(LedgerStore::class));
        try {
            $book->hold('sess', '2026-10-01', 'ticket-1', 100, [
                'lines' => [['account_id' => 'acct', 'debit' => 10, 'credit' => 0, 'subledger_type' => null, 'subledger_ref' => null]],
                'sum' => 10,
                'check' => null,
                'cash' => 10,
            ]);
            self::fail('a blank role was parked');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a layaway takes cash, card, or a check', $error->getMessage());
        }
    }

    public function testACheckLayawayNeedsANumber(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnOnConsecutiveCalls(
            [['role' => 'checks']],
            [['balance' => 0]],
        );
        $statements->method('transaction')->willReturnCallback(static function (callable $work): mixed {
            $work();

            return null;
        });
        $book = new LayawayBook($statements, $this->createStub(LedgerStore::class));
        try {
            $book->hold('sess', '2026-10-01', 'ticket-1', 100, [
                'lines' => [['account_id' => 'acct', 'debit' => 10, 'credit' => 0, 'subledger_type' => null, 'subledger_ref' => null]],
                'sum' => 10,
                'check' => '',
                'cash' => 0,
            ]);
            self::fail('a check without a number was parked');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a check sale needs a check number', $error->getMessage());
        }
    }

    public function testCancelNeedsAParkedTicket(): void
    {
        $book = new LayawayBook($this->createStub(Statements::class), $this->createStub(LedgerStore::class));
        try {
            $book->cancel('sess', '2026-10-01', '');
            self::fail('a blank ticket was refunded');
        } catch (DatabaseRefusal $error) {
            self::assertSame('that ticket is not parked', $error->getMessage());
        }
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([[
            'id' => 'dep-1',
            'amount' => 10,
            'tender' => 'cash',
            'check_number' => '',
        ]]);
        $statements->method('transaction')->willReturnCallback(static function (callable $work): mixed {
            $work();

            return null;
        });
        $statements->method('affected')->willReturn(0);
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn('acct');
        try {
            (new LayawayBook($statements, $ledger))->cancel('sess', '2026-10-01', 'ticket-1');
            self::fail('an open ticket was treated as parked');
        } catch (DatabaseRefusal $error) {
            self::assertSame('that ticket is not parked', $error->getMessage());
        }
    }

    public function testDepositsAreIncludedOnTheTender(): void
    {
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn('layaway');
        $book = new LayawayBook($this->createStub(Statements::class), $ledger);
        $tender = $book->include('ticket-1', 25, [
            'lines' => [],
            'sum' => 10,
            'check' => null,
            'cash' => 10,
        ]);

        self::assertSame(35, $tender['sum']);
        self::assertSame('layaway', $tender['lines'][0]['account_id']);
        self::assertSame(25, $tender['lines'][0]['debit']);
    }
}
