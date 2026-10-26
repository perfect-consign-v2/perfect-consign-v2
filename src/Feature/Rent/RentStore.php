<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use DateTimeImmutable;
use InvalidArgumentException;
use PerfectConsign\Feature\Mall\MallSettings;
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
        return $this->assignmentRate($boothId, $date);
    }

    public function chargeAmount(string $boothId, string $date): int
    {
        $cycle = new RentCycle($this->cycleDay());
        $stay = $this->covering($boothId, $date);
        if ($stay === null) {
            throw new DatabaseRefusal('a rent charge requires an assignment that covers the charge date');
        }
        $periodStart = $cycle->start($date);
        $rateAsOf = $stay['starts'] > $periodStart ? $stay['starts'] : $periodStart;
        $rate = $this->assignmentRate($boothId, $rateAsOf);
        if ($rate === null) {
            throw new DatabaseRefusal('a rent charge requires the booth rate from the assignment');
        }

        return $cycle->amount($rate, $stay['starts'], $date);
    }

    public function postCharge(string $boothId, string $postingDate): void
    {
        $amount = $this->chargeAmount($boothId, $postingDate);
        $cycle = new RentCycle($this->cycleDay());
        $suffix = $cycle->suffix($cycle->start($postingDate));
        $this->ledger->post(new JournalDraft(
            postingKey: 'rent-charge:' . $boothId . ':' . $suffix,
            postingDate: $postingDate,
            source: new JournalSource('rent_charge', $boothId . ':' . $suffix, 'Rent charge'),
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
        $cycle = new RentCycle($this->cycleDay());
        $months = $this->openPeriods($today, $cycle->suffixesTouching($exceptMonth));
        if ($months === []) {
            return [];
        }
        $booths = $this->statements->rows('SELECT id::text AS id, code, name FROM booths');
        $openRent = $this->openRentRows();
        $due = [];
        foreach ($months as $month) {
            array_push($due, ...$this->dueLines($cycle, $month, $today, $booths, $openRent));
        }

        return $due;
    }

    /**
     * Months other than the one on screen that still have at least one charge button.
     *
     * @return list<array{month: string, label: string, count: int}>
     */
    public function unchargedMonths(string $today, string $exceptMonth): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
            throw new InvalidArgumentException('a rent day is YYYY-MM-DD');
        }
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $exceptMonth) !== 1) {
            throw new InvalidArgumentException('a rent month is YYYY-MM');
        }
        $skip = (new RentCycle($this->cycleDay()))->suffixesTouching($exceptMonth);
        $listed = [];
        foreach ($this->statements->bound(
            "SELECT stall_rent_charge_suffix(series.period_start::date) AS month,
                    count(DISTINCT assignment.booth_id) AS booths
             FROM booth_assignments AS assignment
             JOIN generate_series(
                 stall_rent_period_start(assignment.starts_on),
                 stall_rent_period_start(?::date),
                 interval '1 month'
             ) AS series(period_start) ON true
             WHERE assignment.starts_on <= stall_rent_period_end(series.period_start::date)
               AND (assignment.ends_on IS NULL OR assignment.ends_on >= series.period_start::date)
               AND NOT EXISTS (
                   SELECT 1
                   FROM journals AS posted
                   WHERE posted.source_type = 'rent_charge'
                     AND posted.posting_key = 'rent-charge:' || assignment.booth_id::text || ':' || stall_rent_charge_suffix(series.period_start::date)
                     AND NOT EXISTS (
                         SELECT 1 FROM journals AS reversal
                         WHERE reversal.reverses_journal_id = posted.id
                     )
               )
             GROUP BY month
             ORDER BY month",
            [$today]
        ) as $row) {
            $month = RowValue::string($row['month'] ?? null);
            if (in_array($month, $skip, true)) {
                continue;
            }
            $listed[] = [
                'month' => $month,
                'label' => $this->periodLabel($month),
                'count' => RowValue::integer($row['booths'] ?? null),
            ];
        }

        return $listed;
    }

    /**
     * @param list<string> $skip
     * @return list<string>
     */
    private function openPeriods(string $today, array $skip): array
    {
        $months = [];
        foreach ($this->statements->bound(
            "SELECT DISTINCT stall_rent_charge_suffix(series.period_start::date) AS month
             FROM booth_assignments AS assignment
             JOIN generate_series(
                 stall_rent_period_start(assignment.starts_on),
                 stall_rent_period_start(?::date),
                 interval '1 month'
             ) AS series(period_start) ON true
             WHERE assignment.starts_on <= stall_rent_period_end(series.period_start::date)
               AND (assignment.ends_on IS NULL OR assignment.ends_on >= series.period_start::date)
               AND NOT EXISTS (
                   SELECT 1
                   FROM journals AS posted
                   WHERE posted.source_type = 'rent_charge'
                     AND posted.posting_key = 'rent-charge:' || assignment.booth_id::text || ':' || stall_rent_charge_suffix(series.period_start::date)
                     AND NOT EXISTS (
                         SELECT 1 FROM journals AS reversal
                         WHERE reversal.reverses_journal_id = posted.id
                     )
               )
             ORDER BY month",
            [$today]
        ) as $row) {
            $month = RowValue::string($row['month'] ?? null);
            if (!in_array($month, $skip, true)) {
                $months[] = $month;
            }
        }

        return $months;
    }

    /**
     * @param list<array<string, mixed>> $booths
     * @param list<array<string, mixed>> $openRent
     * @return list<array{booth_id: string, code: string, vendor_name: ?string, month: string, label: string, amount_minor: int, charge_date: string}>
     */
    private function dueLines(RentCycle $cycle, string $month, string $today, array $booths, array $openRent): array
    {
        $page = strlen($month) === 7 ? $month : substr($month, 0, 7);
        $period = strlen($month) === 7 ? null : $month;
        $start = $period ?? $page . '-01';
        $end = $period === null
            ? (new DateTimeImmutable($page . '-01'))->modify('last day of this month')->format('Y-m-d')
            : $cycle->end($period);
        $facts = [
            'booths' => $booths,
            'assignments' => $this->assignmentRows($start, $end),
            'rates' => $this->rates($start, $end),
            'charges' => $this->chargeFacts($page, $period),
            'open_rent' => $openRent,
        ];
        $due = [];
        foreach ($this->lines->build($facts, $page, $today, $cycle->day(), $period) as $line) {
            if ($line['charged'] || $line['rate_minor'] === null || !$line['chargeable']) {
                continue;
            }
            $due[] = [
                'booth_id' => $line['id'],
                'code' => $line['code'],
                'vendor_name' => $line['vendor_name'],
                'month' => $month,
                'label' => $this->periodLabel($month),
                'amount_minor' => $line['rate_minor'],
                'charge_date' => $line['charge_date'],
            ];
        }

        return $due;
    }

    private function periodLabel(string $month): string
    {
        $labelOn = strlen($month) === 7 ? $month . '-01' : $month;

        return (new DateTimeImmutable($labelOn))->format(strlen($month) === 7 ? 'F Y' : 'M j, Y');
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
    public function facts(string $month, ?string $windowStart = null, ?string $windowEnd = null, ?string $chargeSuffix = null): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new InvalidArgumentException('a rent month is YYYY-MM');
        }
        $start = $windowStart ?? $month . '-01';
        $end = $windowEnd ?? (new DateTimeImmutable($month . '-01'))->modify('last day of this month')->format('Y-m-d');

        return [
            'booths' => $this->statements->rows('SELECT id::text AS id, code, name FROM booths'),
            'assignments' => $this->assignmentRows($start, $end),
            'rates' => $this->rates($start, $end),
            'charges' => $this->chargeFacts($month, $chargeSuffix),
            'open_rent' => $this->openRentRows(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assignmentRows(string $start, string $end): array
    {
        return $this->statements->bound(
            "SELECT assignment.booth_id::text AS booth_id,
                    assignment.vendor_id::text AS vendor_id,
                    vendor.name AS vendor_name,
                    to_char(assignment.starts_on, 'YYYY-MM-DD') AS starts_on,
                    to_char(assignment.ends_on, 'YYYY-MM-DD') AS ends_on
             FROM booth_assignments AS assignment
             JOIN vendors AS vendor ON vendor.id = assignment.vendor_id
             WHERE assignment.starts_on <= ?::date
               AND (assignment.ends_on IS NULL OR assignment.ends_on >= ?::date)",
            [$end, $start]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openRentRows(): array
    {
        return $this->statements->rows(
            "SELECT line.subledger_ref AS booth_id,
                    sum(line.debit_minor - line.credit_minor) AS open_minor
             FROM journal_lines AS line
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = 'booth_rent_receivable'
               AND line.subledger_type = 'booth'
             GROUP BY line.subledger_ref
             HAVING sum(line.debit_minor - line.credit_minor) > 0"
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function chargeFacts(string $month, ?string $only = null): array
    {
        $patterns = [];
        $suffixes = $only === null ? (new RentCycle($this->cycleDay()))->suffixesTouching($month) : [$only];
        foreach ($suffixes as $suffix) {
            $patterns[] = 'rent-charge:%:' . $suffix;
        }
        $first = $patterns[0];
        $second = $patterns[1] ?? $first;

        return $this->statements->bound(
            "SELECT posted.id::text AS journal_id,
                    posted.posting_key,
                    posted.debit_total_minor
             FROM journals AS posted
             WHERE posted.source_type = 'rent_charge'
               AND (posted.posting_key LIKE ? OR posted.posting_key LIKE ?)
               AND NOT EXISTS (
                   SELECT 1 FROM journals AS reversal
                   WHERE reversal.reverses_journal_id = posted.id
               )",
            [$first, $second]
        );
    }

    private function cycleDay(): int
    {
        $rows = $this->statements->rows('SELECT rent_cycle_day FROM mall');
        $day = RowValue::integer($rows[0]['rent_cycle_day'] ?? null);

        return $day >= 1 && $day <= 28 ? $day : 1;
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
                        rate.amount_minor
                 FROM booth_assignments AS assignment
                 JOIN assignment_rent_rates AS rate ON rate.assignment_id = assignment.id
                 WHERE assignment.starts_on <= ?::date
                   AND (assignment.ends_on IS NULL OR assignment.ends_on >= ?::date)
                   AND rate.starts_on <= ?::date
                 ORDER BY assignment.booth_id, assignment.starts_on DESC, rate.starts_on DESC",
                [$end, $start, $end]
            ),
            $this->statements->bound(
                "SELECT rate.booth_id::text AS booth_id, rate.amount_minor
                 FROM booth_rent_rates AS rate
                 WHERE NOT EXISTS (
                     SELECT 1
                     FROM booth_assignments AS assignment
                     WHERE assignment.booth_id = rate.booth_id
                       AND assignment.starts_on <= ?::date
                       AND (assignment.ends_on IS NULL OR assignment.ends_on >= ?::date)
                 )
                 ORDER BY rate.booth_id",
                [$end, $start]
            )
        );
    }

    private function assignmentRate(string $boothId, string $date): ?int
    {
        $rows = $this->statements->bound(
            "SELECT rate.amount_minor
             FROM booth_assignments AS assignment
             JOIN assignment_rent_rates AS rate ON rate.assignment_id = assignment.id
             WHERE assignment.booth_id = ?::uuid
               AND assignment.starts_on <= ?::date
               AND (assignment.ends_on IS NULL OR assignment.ends_on >= ?::date)
               AND rate.starts_on <= ?::date
             ORDER BY assignment.starts_on DESC, rate.starts_on DESC
             LIMIT 1",
            [$boothId, $date, $date, $date]
        );
        $amount = $rows[0]['amount_minor'] ?? null;
        if ($amount === null || $amount === '') {
            return null;
        }

        return RowValue::integer($amount);
    }

    /**
     * @return array{starts: string}|null
     */
    private function covering(string $boothId, string $date): ?array
    {
        $rows = $this->statements->bound(
            "SELECT to_char(assignment.starts_on, 'YYYY-MM-DD') AS starts_on
             FROM booth_assignments AS assignment
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

        return [
            'starts' => RowValue::string($rows[0]['starts_on'] ?? null),
        ];
    }
}
