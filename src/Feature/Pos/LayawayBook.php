<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class LayawayBook
{
    /** @var list<string> */
    private const TENDERS = ['cash', 'card', 'checks'];

    public function __construct(
        private readonly Statements $statements,
        private readonly LedgerStore $ledger,
    ) {
    }

    public function balance(string $ticketId): int
    {
        if ($ticketId === '') {
            return 0;
        }
        $rows = $this->statements->bound(
            "SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) AS balance
             FROM journal_lines AS line
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = 'layaway_deposit'
               AND line.subledger_type = 'ticket'
               AND line.subledger_ref = ?",
            [$ticketId]
        );

        return RowValue::integer($rows[0]['balance'] ?? null);
    }

    public function deposit(
        string $session,
        string $date,
        string $ticketId,
        int $amount,
        int $due,
        string $tender,
        ?string $check,
    ): void {
        $this->guard($ticketId, $amount, $due, $tender, $check);
        $this->ledger->post(new JournalDraft(
            'layaway:' . $ticketId . ':' . $this->nextKey($ticketId),
            $date,
            new JournalSource('layaway_deposit', $ticketId, 'Layaway deposit'),
            [
                PostedLines::entry($this->ledger->roleAccount($tender), $amount, 0, null, null),
                PostedLines::entry($this->ledger->roleAccount('layaway_deposit'), 0, $amount, 'ticket', $ticketId),
            ],
            sessionId: $session,
            checkNumber: $tender === 'checks' ? $check : null,
            beforeCommit: function () use ($ticketId): void {
                $updated = $this->statements->affected(
                    "UPDATE tickets SET status = 'layaway' WHERE id = ?::uuid AND status = 'open'",
                    [$ticketId]
                );
                if ($updated !== 1) {
                    throw new DatabaseRefusal('there is no open ticket');
                }
            },
        ));
    }

    public function cancel(string $session, string $date, string $ticketId): void
    {
        if ($ticketId === '') {
            throw new DatabaseRefusal('that ticket is not parked');
        }
        foreach ($this->openDeposits($ticketId) as $deposit) {
            $this->ledger->post(new JournalDraft(
                'layaway-refund:' . $deposit['id'],
                $date,
                new JournalSource('layaway_refund', $deposit['id'], 'Layaway refund'),
                [
                    PostedLines::entry($this->ledger->roleAccount('layaway_deposit'), $deposit['amount'], 0, 'ticket', $ticketId),
                    PostedLines::entry($this->ledger->roleAccount($deposit['tender']), 0, $deposit['amount'], null, null),
                ],
                sessionId: $session,
                checkNumber: $deposit['check'],
            ));
        }
        $deleted = $this->statements->affected(
            "DELETE FROM tickets WHERE id = ?::uuid AND status = 'layaway'",
            [$ticketId]
        );
        if ($deleted !== 1) {
            throw new DatabaseRefusal('that ticket is not parked');
        }
    }

    /**
     * @param array{lines: list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>, sum: int, check: ?string, cash: int} $tender
     * @return array{lines: list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>, sum: int, check: ?string, cash: int}
     */
    public function include(string $ticketId, int $deposits, array $tender): array
    {
        if ($deposits < 1) {
            return $tender;
        }
        $tender['lines'][] = PostedLines::entry(
            $this->ledger->roleAccount('layaway_deposit'),
            $deposits,
            0,
            'ticket',
            $ticketId
        );
        $tender['sum'] += $deposits;

        return $tender;
    }

    private function guard(string $ticketId, int $amount, int $due, string $tender, ?string $check): void
    {
        if ($ticketId === '' || $amount < 1 || !in_array($tender, self::TENDERS, true)) {
            throw new DatabaseRefusal($ticketId === '' ? 'there is no open ticket' : 'a layaway deposit is the tender and the deposit');
        }
        if ($tender === 'checks' && ($check === null || $check === '')) {
            throw new DatabaseRefusal('a check sale needs a check number');
        }
        if ($amount > $due - $this->balance($ticketId)) {
            throw new DatabaseRefusal('a layaway deposit cannot pass the amount due');
        }
    }

    private function nextKey(string $ticketId): string
    {
        $rows = $this->statements->bound(
            "SELECT count(*) AS taken FROM journals WHERE source_type = 'layaway_deposit' AND source_reference = ?",
            [$ticketId]
        );

        return (string) (RowValue::integer($rows[0]['taken'] ?? null) + 1);
    }

    /**
     * @return list<array{id: string, amount: int, tender: string, check: ?string}>
     */
    private function openDeposits(string $ticketId): array
    {
        $rows = $this->statements->bound(
            "SELECT journal.id, tender.debit_minor AS amount, role.role AS tender, checks.check_number
             FROM journals AS journal
             JOIN journal_lines AS tender ON tender.journal_id = journal.id AND tender.debit_minor > 0
             JOIN chart_roles AS role ON role.account_id = tender.account_id
             LEFT JOIN register_checks AS checks ON checks.journal_id = journal.id
             WHERE journal.source_type = 'layaway_deposit'
               AND journal.source_reference = ?
               AND role.role IN ('cash', 'card', 'checks')
               AND NOT EXISTS (
                   SELECT 1 FROM journals AS refund
                   WHERE refund.source_type = 'layaway_refund'
                     AND refund.source_reference = journal.id::text
               )",
            [$ticketId]
        );
        $deposits = [];
        foreach ($rows as $row) {
            $check = $row['check_number'] ?? null;
            $deposits[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'amount' => RowValue::integer($row['amount'] ?? null),
                'tender' => RowValue::string($row['tender'] ?? null),
                'check' => is_string($check) && $check !== '' ? $check : null,
            ];
        }

        return $deposits;
    }
}
