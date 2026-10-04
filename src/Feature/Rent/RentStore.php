<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use DateTimeImmutable;
use InvalidArgumentException;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Party\MoveInRent;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class RentStore
{
    public function __construct(
        private readonly Statements $statements,
        private readonly MallSettings $mall,
    ) {
    }

    public function today(): string
    {
        return $this->mall->today();
    }

    public function rate(string $boothId, string $date): ?int
    {
        $rows = $this->statements->bound(
            "SELECT amount_minor FROM booth_rent_rates
             WHERE booth_id = ?::uuid AND month_start = date_trunc('month', ?::date)::date",
            [$boothId, $date]
        );
        if ($rows === []) {
            return null;
        }

        return RowValue::integer($rows[0]['amount_minor'] ?? null);
    }

    public function chargeAmount(string $boothId, string $date): int
    {
        $rate = $this->rate($boothId, $date);
        if ($rate === null) {
            throw new DatabaseRefusal('a rent charge requires the rate stored for that month');
        }
        $stay = $this->covering($boothId, $date);
        if ($stay === null) {
            throw new DatabaseRefusal('a rent charge requires an assignment that covers the charge date');
        }

        return MoveInRent::charge($rate, $stay['override'], $stay['starts'], substr($date, 0, 7));
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
            'rates' => $this->statements->bound(
                'SELECT booth_id::text AS booth_id, amount_minor
                 FROM booth_rent_rates
                 WHERE month_start = ?::date',
                [$start]
            ),
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
