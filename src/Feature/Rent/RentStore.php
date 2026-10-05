<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use DateTimeImmutable;
use InvalidArgumentException;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Party\MoveInRent;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class RentStore
{
    public function __construct(
        private readonly Statements $statements,
        private readonly MallSettings $mall,
        private readonly RentLines $lines,
        private readonly LedgerStore $ledger,
    ) {
    }

    public function today(): string
    {
        return $this->mall->today();
    }

    public function rate(string $boothId, string $date): ?int
    {
        $rows = $this->statements->bound(
            "SELECT COALESCE(override.amount_minor, listed.amount_minor) AS amount_minor
             FROM booth_assignments AS assignment
             LEFT JOIN assignment_rent AS override ON override.assignment_id = assignment.id
             LEFT JOIN LATERAL (
                 SELECT amount_minor
                 FROM booth_rent_rates
                 WHERE booth_id = assignment.booth_id
                   AND month_start <= assignment.starts_on
                 ORDER BY month_start DESC
                 LIMIT 1
             ) AS listed ON true
             WHERE assignment.booth_id = ?::uuid
               AND assignment.starts_on <= ?::date
               AND (assignment.ends_on IS NULL OR assignment.ends_on >= ?::date)
             ORDER BY assignment.starts_on DESC
             LIMIT 1",
            [$boothId, $date, $date]
        );
        $amount = $rows[0]['amount_minor'] ?? null;
        if ($amount === null || $amount === '') {
            return null;
        }

        return RowValue::integer($amount);
    }

    public function chargeAmount(string $boothId, string $date): int
    {
        $stay = $this->covering($boothId, $date);
        if ($stay === null) {
            throw new DatabaseRefusal('a rent charge requires an assignment that covers the charge date');
        }
        $rate = $this->rate($boothId, $date);
        if ($rate === null) {
            throw new DatabaseRefusal('a rent charge requires the booth rate from the assignment');
        }

        return MoveInRent::charge($rate, $stay['override'], $stay['starts'], substr($date, 0, 7));
    }

    public function postCharge(string $boothId, string $postingDate): void
    {
        $amount = $this->chargeAmount($boothId, $postingDate);
        $month = substr($postingDate, 0, 7);
        $this->ledger->post(new JournalDraft(
            postingKey: 'rent-charge:' . $boothId . ':' . $month,
            postingDate: $postingDate,
            source: new JournalSource('rent_charge', $boothId . ':' . $month, 'Rent charge'),
            lines: [
                PostedLines::entry($this->ledger->roleAccount('booth_rent_receivable'), $amount, 0, 'booth', $boothId),
                PostedLines::entry($this->ledger->roleAccount('rent_revenue'), 0, $amount, null, null),
            ],
        ));
    }

    /**
     * Months other than the one on screen that still have the charge button the rent book would show.
     *
     * @return list<array{booth_id: string, code: string, vendor_name: ?string, month: string, label: string, amount_minor: int, charge_date: string}>
     */
    public function uncharged(string $today, string $exceptMonth): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
            throw new InvalidArgumentException('a rent day is YYYY-MM-DD');
        }
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $exceptMonth) !== 1) {
            throw new InvalidArgumentException('a rent month is YYYY-MM');
        }
        $due = [];
        foreach ($this->statements->bound(
            "SELECT DISTINCT to_char(rate.month_start, 'YYYY-MM') AS month
             FROM booth_rent_rates AS rate
             JOIN booth_assignments AS assignment ON assignment.booth_id = rate.booth_id
               AND assignment.starts_on <= (rate.month_start + interval '1 month' - interval '1 day')::date
               AND (assignment.ends_on IS NULL OR assignment.ends_on >= rate.month_start)
             WHERE rate.month_start <= date_trunc('month', ?::date)::date
               AND to_char(rate.month_start, 'YYYY-MM') <> ?
               AND NOT EXISTS (
                   SELECT 1
                   FROM journals AS posted
                   WHERE posted.source_type = 'rent_charge'
                     AND posted.posting_key = 'rent-charge:' || rate.booth_id::text || ':' || to_char(rate.month_start, 'YYYY-MM')
                     AND NOT EXISTS (
                         SELECT 1 FROM journals AS reversal
                         WHERE reversal.reverses_journal_id = posted.id
                     )
               )
             ORDER BY month",
            [$today, $exceptMonth]
        ) as $row) {
            $month = RowValue::string($row['month'] ?? null);
            $label = (new DateTimeImmutable($month . '-01'))->format('F Y');
            foreach ($this->lines->build($this->facts($month), $month, $today) as $line) {
                if ($line['charged'] || $line['rate_minor'] === null || !$line['chargeable']) {
                    continue;
                }
                $due[] = [
                    'booth_id' => $line['id'],
                    'code' => $line['code'],
                    'vendor_name' => $line['vendor_name'],
                    'month' => $month,
                    'label' => $label,
                    'amount_minor' => $line['rate_minor'],
                    'charge_date' => $line['charge_date'],
                ];
            }
        }

        return $due;
    }

    /**
     * @return array{
     *   booths: list<array<string, mixed>>,
     *   assignments: list<array<string, mixed>>,
     *   rates: list<array<string, mixed>>,
     *   charges: list<array<string, mixed>>,
     *   open_rent: list<array<string, mixed>>
     * }
     */
    public function facts(string $month): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new InvalidArgumentException('a rent month is YYYY-MM');
        }
        $start = $month . '-01';
        $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');

        return [
            'booths' => $this->statements->rows('SELECT id::text AS id, code, name FROM booths'),
            'assignments' => $this->statements->bound(
                "SELECT assignment.booth_id::text AS booth_id,
                        assignment.vendor_id::text AS vendor_id,
                        vendor.name AS vendor_name,
                        to_char(assignment.starts_on, 'YYYY-MM-DD') AS starts_on,
                        to_char(assignment.ends_on, 'YYYY-MM-DD') AS ends_on,
                        override.amount_minor AS override_minor
                 FROM booth_assignments AS assignment
                 JOIN vendors AS vendor ON vendor.id = assignment.vendor_id
                 LEFT JOIN assignment_rent AS override ON override.assignment_id = assignment.id
                 WHERE assignment.starts_on <= ?::date
                   AND (assignment.ends_on IS NULL OR assignment.ends_on >= ?::date)",
                [$end, $start]
            ),
            'rates' => $this->rates($start, $end),
            'charges' => $this->statements->bound(
                "SELECT posted.id::text AS journal_id,
                        posted.posting_key,
                        posted.debit_total_minor
                 FROM journals AS posted
                 WHERE posted.source_type = 'rent_charge'
                   AND posted.posting_key LIKE ?
                   AND NOT EXISTS (
                       SELECT 1 FROM journals AS reversal
                       WHERE reversal.reverses_journal_id = posted.id
                   )",
                ['rent-charge:%:' . $month]
            ),
            'open_rent' => $this->statements->rows(
                "SELECT line.subledger_ref AS booth_id,
                        sum(line.debit_minor - line.credit_minor) AS open_minor
                 FROM journal_lines AS line
                 JOIN chart_roles AS role ON role.account_id = line.account_id
                 WHERE role.role = 'booth_rent_receivable'
                   AND line.subledger_type = 'booth'
                 GROUP BY line.subledger_ref
                 HAVING sum(line.debit_minor - line.credit_minor) > 0"
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rates(string $start, string $end): array
    {
        return array_merge(
            $this->statements->bound(
                "SELECT DISTINCT ON (assignment.booth_id)
                        assignment.booth_id::text AS booth_id,
                        COALESCE(override.amount_minor, listed.amount_minor) AS amount_minor
                 FROM booth_assignments AS assignment
                 LEFT JOIN assignment_rent AS override ON override.assignment_id = assignment.id
                 LEFT JOIN LATERAL (
                     SELECT amount_minor
                     FROM booth_rent_rates
                     WHERE booth_id = assignment.booth_id
                       AND month_start <= assignment.starts_on
                     ORDER BY month_start DESC
                     LIMIT 1
                 ) AS listed ON true
                 WHERE assignment.starts_on <= ?::date
                   AND (assignment.ends_on IS NULL OR assignment.ends_on >= ?::date)
                   AND COALESCE(override.amount_minor, listed.amount_minor) IS NOT NULL
                 ORDER BY assignment.booth_id, assignment.starts_on DESC",
                [$end, $start]
            ),
            $this->statements->bound(
                "SELECT DISTINCT ON (rate.booth_id)
                        rate.booth_id::text AS booth_id,
                        rate.amount_minor
                 FROM booth_rent_rates AS rate
                 WHERE rate.month_start <= ?::date
                   AND NOT EXISTS (
                       SELECT 1
                       FROM booth_assignments AS assignment
                       WHERE assignment.booth_id = rate.booth_id
                         AND assignment.starts_on <= ?::date
                         AND (assignment.ends_on IS NULL OR assignment.ends_on >= ?::date)
                   )
                 ORDER BY rate.booth_id, rate.month_start DESC",
                [$end, $end, $start]
            )
        );
    }

    /**
     * @return array{starts: string, override: ?int}|null
     */
    private function covering(string $boothId, string $date): ?array
    {
        $rows = $this->statements->bound(
            "SELECT to_char(assignment.starts_on, 'YYYY-MM-DD') AS starts_on,
                    override.amount_minor AS override_minor
             FROM booth_assignments AS assignment
             LEFT JOIN assignment_rent AS override ON override.assignment_id = assignment.id
             WHERE assignment.booth_id = ?::uuid
               AND assignment.starts_on <= ?::date
               AND (assignment.ends_on IS NULL OR assignment.ends_on >= ?::date)
             ORDER BY assignment.starts_on DESC
             LIMIT 1",
            [$boothId, $date, $date]
        );
        if ($rows === []) {
            return null;
        }
        $override = $rows[0]['override_minor'] ?? null;

        return [
            'starts' => RowValue::string($rows[0]['starts_on'] ?? null),
            'override' => $override === null || $override === '' ? null : RowValue::integer($override),
        ];
    }
}
