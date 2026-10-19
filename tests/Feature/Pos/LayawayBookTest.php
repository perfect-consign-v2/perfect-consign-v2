<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Pos\LayawayBook;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
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

    public function testOneCentOfDepositIsIncluded(): void
    {
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn('layaway');
        $tender = (new LayawayBook($this->createStub(Statements::class), $ledger))->include('ticket-1', 1, [
            'lines' => [],
            'sum' => 0,
            'check' => null,
            'cash' => 0,
        ]);

        self::assertSame(1, $tender['sum']);
        self::assertSame(1, $tender['lines'][0]['debit']);
    }

    public function testAOneCentCashDepositPostsTheNextKey(): void
    {
        $draft = $this->posted('cash', 1, 1, 100, 0, null);

        self::assertSame('layaway:ticket-1:1', $draft->postingKey);
        self::assertNull($draft->checkNumber);
    }

    public function testACheckDepositKeepsItsNumber(): void
    {
        $draft = $this->posted('checks', 10, 10, 100, 0, '1234');

        self::assertSame('1234', $draft->checkNumber);
    }

    public function testABlankTicketWithATenderIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnOnConsecutiveCalls(
            [['role' => 'cash']],
            [['balance' => 0]],
        );
        $this->expectRefusal(new LayawayBook($statements, $this->createStub(LedgerStore::class)), '', 100, 10, 10, 'cash', null, 'there is no open ticket');
    }

    public function testAZeroDebitLineIsNotADeposit(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['balance' => 0]]);
        $book = new LayawayBook($statements, $this->createStub(LedgerStore::class));
        try {
            $book->hold('sess', '2026-10-01', 'ticket-1', 100, [
                'lines' => [['account_id' => 'acct', 'debit' => 0, 'credit' => 10, 'subledger_type' => null, 'subledger_ref' => null]],
                'sum' => 10,
                'check' => null,
                'cash' => 10,
            ]);
            self::fail('a zero debit was parked');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a layaway deposit cannot pass the amount due', $error->getMessage());
        }
    }

    public function testAZeroSumIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnOnConsecutiveCalls(
            [['role' => 'cash']],
            [['balance' => 0]],
        );
        $this->expectRefusal(new LayawayBook($statements, $this->createStub(LedgerStore::class)), 'ticket-1', 100, 10, 0, 'cash', null, 'a layaway deposit cannot pass the amount due');
    }

    public function testADepositThatMeetsTheOpenBalanceIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnOnConsecutiveCalls(
            [['role' => 'cash']],
            [['balance' => 40]],
        );
        $this->expectRefusal(new LayawayBook($statements, $this->createStub(LedgerStore::class)), 'ticket-1', 100, 60, 60, 'cash', null, 'a layaway deposit cannot pass the amount due');
    }

    public function testOneLineCannotPassTheOpenBalance(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnOnConsecutiveCalls(
            [['role' => 'cash']],
            [['balance' => 30]],
            [['balance' => 30]],
        );
        $statements->method('transaction')->willReturnCallback(static function (callable $work): mixed {
            $work();

            return null;
        });
        $this->expectRefusal(new LayawayBook($statements, $this->createStub(LedgerStore::class)), 'ticket-1', 100, 80, 10, 'cash', null, 'a layaway deposit cannot pass the amount due');
    }

    public function testALineMayTakeExactlyWhatIsStillOpen(): void
    {
        $draft = $this->posted('cash', 70, 10, 100, 30, '999');

        self::assertNull($draft->checkNumber);
        self::assertSame(70, $draft->lines[0]['debit']);
    }

    public function testARefundKeepsACheckNumberAndDropsABlankOne(): void
    {
        self::assertSame('99', $this->refunded('99')->checkNumber);
        self::assertNull($this->refunded('')->checkNumber);
    }

    private function posted(string $role, int $debit, int $sum, int $due, int $balance, ?string $check): JournalDraft
    {
        $draft = null;
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnOnConsecutiveCalls(
            [['role' => $role]],
            [['balance' => $balance]],
            [['balance' => $balance]],
            [['taken' => 0]],
        );
        $statements->method('transaction')->willReturnCallback(static function (callable $work): mixed {
            $work();

            return null;
        });
        $statements->method('affected')->willReturn(1);
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn('acct-' . $role);
        $ledger->method('post')->willReturnCallback(static function (JournalDraft $posted) use (&$draft): string {
            $draft = $posted;

            return 'journal-1';
        });
        (new LayawayBook($statements, $ledger))->hold('sess', '2026-10-01', 'ticket-1', $due, [
            'lines' => [['account_id' => 'acct', 'debit' => $debit, 'credit' => 0, 'subledger_type' => null, 'subledger_ref' => null]],
            'sum' => $sum,
            'check' => $check,
            'cash' => $role === 'cash' ? $sum : 0,
        ]);
        self::assertInstanceOf(JournalDraft::class, $draft);

        return $draft;
    }

    private function refunded(string $checkNumber): JournalDraft
    {
        $draft = null;
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([[
            'id' => 'dep-1',
            'amount' => 10,
            'tender' => 'checks',
            'check_number' => $checkNumber,
        ]]);
        $statements->method('transaction')->willReturnCallback(static function (callable $work): mixed {
            $work();

            return null;
        });
        $statements->method('affected')->willReturn(1);
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn('acct');
        $ledger->method('post')->willReturnCallback(static function (JournalDraft $posted) use (&$draft): string {
            $draft = $posted;

            return 'journal-1';
        });
        (new LayawayBook($statements, $ledger))->cancel('sess', '2026-10-01', 'ticket-1');
        self::assertInstanceOf(JournalDraft::class, $draft);

        return $draft;
    }

    private function expectRefusal(LayawayBook $book, string $ticketId, int $due, int $debit, int $sum, string $role, ?string $check, string $message): void
    {
        try {
            $book->hold('sess', '2026-10-01', $ticketId, $due, [
                'lines' => [['account_id' => 'acct', 'debit' => $debit, 'credit' => 0, 'subledger_type' => null, 'subledger_ref' => null]],
                'sum' => $sum,
                'check' => $check,
                'cash' => $role === 'cash' ? $sum : 0,
            ]);
            self::fail('the layaway deposit was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame($message, $error->getMessage());
        }
    }
}
