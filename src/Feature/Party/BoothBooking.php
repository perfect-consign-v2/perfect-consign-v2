<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\PostingFunctions;
use PerfectConsign\Shared\Database\PgBool;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * Assigning a booth with its first rent, ending a booth, and cancelling a
 * reservation, each through the database function that decides the amount
 * and refuses what the store does not allow.
 *
 * @phpstan-type BoothDue array{due: int, first_period: string, period_end: string, paid_ahead: bool}
 */
final class BoothBooking
{
    private const TENDERS = ['cash', 'card', 'check'];

    public function __construct(
        private readonly Statements $statements,
        private readonly PostingFunctions $postings,
        private readonly JournalAudit $audit,
    ) {
    }

    /**
     * What assigning would take now, or null when the booth has no rent.
     *
     * @return BoothDue|null
     */
    public function due(string $boothId, string $startsOn, ?int $rentMinor, string $today): ?array
    {
        $rows = $this->statements->bound(
            "SELECT due_minor, to_char(first_period, 'YYYY-MM-DD') AS first_period,
                    to_char(period_end, 'YYYY-MM-DD') AS period_end, paid_ahead
             FROM stall_booth_due(?::uuid, ?::date, ?, ?::date)",
            [$boothId, $startsOn, $rentMinor, $today],
        );
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }

        return [
            'due' => RowValue::integer($row['due_minor'] ?? null),
            'first_period' => RowValue::string($row['first_period'] ?? null),
            'period_end' => RowValue::string($row['period_end'] ?? null),
            'paid_ahead' => PgBool::read($row['paid_ahead'] ?? null),
        ];
    }

    /**
     * Takes the first rent the database quotes, in one way of paying, and
     * creates the stay. Returns the journal that took the money.
     *
     * @param array{booth: string, vendor: string, starts_on: string, ends_on: string, rent: ?int} $stay
     * @param array{way: string, check: string, session: string} $payment
     */
    public function assign(array $stay, array $payment, string $today): string
    {
        $due = $this->due($stay['booth'], $stay['starts_on'], $stay['rent'], $today);
        if ($due === null) {
            throw new DatabaseRefusal('Set the rent for this booth first.');
        }
        [$cash, $card, $checks] = $this->tenders($payment, $due['due']);

        return $this->postings->post(
            $today,
            'stall_assign_booth(?::uuid, ?::uuid, ?::date, ?::date, ?, ?, ?, ?, ?::date)',
            [
                $stay['booth'],
                $stay['vendor'],
                $stay['starts_on'],
                $stay['ends_on'] === '' ? null : $stay['ends_on'],
                $stay['rent'],
                $cash,
                $card,
                $checks,
                $today,
            ],
            $payment['session'],
            $payment['way'] === 'check' ? $payment['check'] : null,
            null,
            function (string $journalId): void {
                $this->audit->record('assignment.create', 'assignment', $this->stayOf($journalId));
            },
        );
    }

    public function end(string $assignmentId, string $endsOn): void
    {
        $this->audit->within(function () use ($assignmentId, $endsOn): void {
            $this->statements->bound('SELECT stall_end_booth(?::uuid, NULLIF(?, \'\')::date)', [$assignmentId, $endsOn]);
            $this->audit->record('assignment.end', 'assignment', $assignmentId);
        });
    }

    /**
     * What a reserved stay still holds, paid ahead.
     */
    public function paidAhead(string $assignmentId): int
    {
        $rows = $this->statements->bound('SELECT stall_rent_paid_ahead(?::uuid) AS held', [$assignmentId]);

        return RowValue::integer($rows[0]['held'] ?? null);
    }

    /**
     * @param array{way: string, check: string, session: string} $payment
     */
    public function cancel(string $assignmentId, string $outcome, array $payment, string $reason, string $today): string
    {
        $held = $this->paidAhead($assignmentId);
        [$cash, $card, $checks] = $outcome === 'refund' ? $this->tenders($payment, $held) : [0, 0, 0];

        return $this->postings->post(
            $today,
            'stall_cancel_reservation(?::uuid, ?, ?, ?, ?, ?::date, ?)',
            [$assignmentId, $outcome, $cash, $card, $checks, $today, $reason],
            $outcome === 'refund' ? $payment['session'] : null,
            $outcome === 'refund' && $payment['way'] === 'check' ? $payment['check'] : null,
            null,
            function () use ($assignmentId): void {
                $this->audit->record('assignment.cancel', 'assignment', $assignmentId);
            },
        );
    }

    /**
     * The whole amount in the one way the person chose.
     *
     * @param array{way: string, check: string, session: string} $payment
     * @return array{0: int, 1: int, 2: int}
     */
    private function tenders(array $payment, int $amount): array
    {
        if (!in_array($payment['way'], self::TENDERS, true)) {
            throw new DatabaseRefusal('Choose how they pay.');
        }
        if ($payment['session'] === '') {
            throw new DatabaseRefusal('Open the register first to take or give back money.');
        }
        if ($payment['way'] === 'check' && $payment['check'] === '') {
            throw new DatabaseRefusal('Enter the check number.');
        }

        return [
            $payment['way'] === 'cash' ? $amount : 0,
            $payment['way'] === 'card' ? $amount : 0,
            $payment['way'] === 'check' ? $amount : 0,
        ];
    }

    private function stayOf(string $journalId): string
    {
        $rows = $this->statements->bound(
            "SELECT DISTINCT subledger_ref FROM journal_lines WHERE journal_id = ?::uuid AND subledger_type = 'assignment'",
            [$journalId],
        );
        if (count($rows) !== 1) {
            throw new DatabaseRefusal('the booth payment names one booth');
        }

        return RowValue::string($rows[0]['subledger_ref'] ?? null);
    }
}
