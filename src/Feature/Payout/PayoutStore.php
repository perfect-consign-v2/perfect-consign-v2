<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PDO;
use PerfectConsign\Feature\Party\MoveInRent;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class PayoutStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Statements $statements,
    ) {
    }

    /**
     * @return array{payable: int, rent: int, booths: list<array{booth_id: string, open_minor: int}>}
     */
    public function openPayout(string $vendorId): array
    {
        $payable = $this->pdo->prepare(
            "SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0)
             FROM journal_lines AS line
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = 'vendor_payable'
               AND line.subledger_type = 'vendor'
               AND line.subledger_ref = ?"
        );
        $payable->execute([$vendorId]);
        $rent = $this->pdo->prepare(
            "SELECT line.subledger_ref AS booth_id, sum(line.debit_minor - line.credit_minor) AS open_minor
             FROM journal_lines AS line
             JOIN journals AS posted ON posted.id = line.journal_id
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = 'booth_rent_receivable'
               AND line.subledger_type = 'booth'
               AND EXISTS (
                   SELECT 1 FROM booth_assignments AS assignment
                   WHERE assignment.vendor_id = ?::uuid
                     AND assignment.booth_id = line.subledger_ref::uuid
                     AND assignment.assignment_range @> posted.posting_date
               )
             GROUP BY line.subledger_ref
             HAVING sum(line.debit_minor - line.credit_minor) > 0"
        );
        $rent->execute([$vendorId]);
        $booths = [];
        $rentTotal = 0;
        foreach ($this->statements->all($rent) as $row) {
            $open = RowValue::integer($row['open_minor'] ?? null);
            $booths[] = ['booth_id' => RowValue::string($row['booth_id'] ?? null), 'open_minor' => $open];
            $rentTotal += $open;
        }

        return [
            'payable' => RowValue::integer($payable->fetchColumn()),
            'rent' => $rentTotal,
            'booths' => $booths,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function statement(string $vendorId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT posting_date, journal_no, source_type, posting_key, role, debit_minor, credit_minor
             FROM vendor_statement
             WHERE vendor_id = ?::uuid
             ORDER BY posting_date, journal_no, line_no'
        );
        $statement->execute([$vendorId]);

        return $this->statements->all($statement);
    }

    /**
     * Rent the assignment owes through today that has not been charged yet.
     *
     * @return list<array{booth_id: string, posting_date: string, amount: int, month: string}>
     */
    public function missingRent(string $vendorId, string $today): array
    {
        $this->rates = null;
        $charged = [];
        foreach ($this->statements->rows(
            "SELECT posting_key FROM journals AS posted
             WHERE posted.source_type = 'rent_charge'
               AND NOT EXISTS (
                   SELECT 1 FROM journals AS reversal WHERE reversal.reverses_journal_id = posted.id
               )"
        ) as $row) {
            $charged[RowValue::string($row['posting_key'] ?? null)] = true;
        }
        $missing = [];
        foreach ($this->assignments($vendorId, $today) as $assignment) {
            foreach ($this->months($assignment, $charged) as $charge) {
                $missing[] = $charge;
            }
        }

        return $missing;
    }

    /**
     * @return array<string, list<array{booth_id: string, posting_date: string, amount: int, month: string}>>
     */
    public function missingByVendor(string $today): array
    {
        $this->rates = null;
        $charged = [];
        foreach ($this->statements->rows(
            "SELECT posting_key FROM journals AS posted
             WHERE posted.source_type = 'rent_charge'
               AND NOT EXISTS (
                   SELECT 1 FROM journals AS reversal WHERE reversal.reverses_journal_id = posted.id
               )"
        ) as $row) {
            $charged[RowValue::string($row['posting_key'] ?? null)] = true;
        }
        $missing = [];
        foreach ($this->statements->rows(
            "SELECT assignment.vendor_id::text AS vendor_id, assignment.booth_id::text AS booth_id,
                    to_char(assignment.starts_on, 'YYYY-MM-DD') AS starts_on,
                    to_char(assignment.ends_on, 'YYYY-MM-DD') AS ends_on,
                    override.amount_minor AS override_minor
             FROM booth_assignments AS assignment
             LEFT JOIN assignment_rent AS override ON override.assignment_id = assignment.id"
        ) as $row) {
            $starts = RowValue::string($row['starts_on'] ?? null);
            $ends = $row['ends_on'] ?? null;
            $through = is_string($ends) && $ends !== '' && $ends < $today ? $ends : $today;
            if ($starts === '' || $starts > $through) {
                continue;
            }
            $vendorId = RowValue::string($row['vendor_id'] ?? null);
            foreach ($this->months([
                'booth_id' => RowValue::string($row['booth_id'] ?? null),
                'starts' => $starts,
                'through' => $through,
                'override' => $this->override($row['override_minor'] ?? null),
            ], $charged) as $charge) {
                $missing[$vendorId][] = $charge;
            }
        }

        return $missing;
    }

    /**
     * @return list<array{booth_id: string, starts: string, through: string, override: ?int}>
     */
    private function assignments(string $vendorId, string $today): array
    {
        $rows = $this->statements->bound(
            "SELECT assignment.booth_id::text AS booth_id,
                    to_char(assignment.starts_on, 'YYYY-MM-DD') AS starts_on,
                    to_char(assignment.ends_on, 'YYYY-MM-DD') AS ends_on,
                    override.amount_minor AS override_minor
             FROM booth_assignments AS assignment
             LEFT JOIN assignment_rent AS override ON override.assignment_id = assignment.id
             WHERE assignment.vendor_id = ?::uuid",
            [$vendorId]
        );
        $assignments = [];
        foreach ($rows as $row) {
            $starts = RowValue::string($row['starts_on'] ?? null);
            $ends = $row['ends_on'] ?? null;
            $through = is_string($ends) && $ends !== '' && $ends < $today ? $ends : $today;
            if ($starts === '' || $starts > $through) {
                continue;
            }
            $assignments[] = [
                'booth_id' => RowValue::string($row['booth_id'] ?? null),
                'starts' => $starts,
                'through' => $through,
                'override' => $this->override($row['override_minor'] ?? null),
            ];
        }

        return $assignments;
    }

    /**
     * @param array{booth_id: string, starts: string, through: string, override: ?int} $assignment
     * @param array<string, true> $charged
     * @return list<array{booth_id: string, posting_date: string, amount: int, month: string}>
     */
    private function months(array $assignment, array $charged): array
    {
        $missing = [];
        $cursor = new \DateTimeImmutable(substr($assignment['starts'], 0, 7) . '-01');
        $last = new \DateTimeImmutable(substr($assignment['through'], 0, 7) . '-01');
        while ($cursor <= $last) {
            $charge = $this->monthCharge($assignment, $cursor, $charged);
            $cursor = $cursor->modify('+1 month');
            if ($charge !== null) {
                $missing[] = $charge;
            }
        }

        return $missing;
    }

    /**
     * @param array{booth_id: string, starts: string, through: string, override: ?int} $assignment
     * @param array<string, true> $charged
     * @return array{booth_id: string, posting_date: string, amount: int, month: string}|null
     */
    private function monthCharge(array $assignment, \DateTimeImmutable $cursor, array $charged): ?array
    {
        $month = $cursor->format('Y-m');
        $boothId = $assignment['booth_id'];
        if (isset($charged['rent-charge:' . $boothId . ':' . $month])) {
            return null;
        }
        $postingDate = $cursor->format('Y-m-d');
        if ($postingDate < $assignment['starts']) {
            $postingDate = $assignment['starts'];
        }
        $monthly = $this->rate($boothId, $postingDate);
        if ($monthly === null) {
            return null;
        }
        $amount = MoveInRent::charge($monthly, $assignment['override'], $assignment['starts'], $month);
        if ($amount <= 0) {
            return null;
        }

        return [
            'booth_id' => $boothId,
            'posting_date' => $postingDate,
            'amount' => $amount,
            'month' => $month,
        ];
    }

    /** @var array<string, list<array{start: string, amount: int}>>|null */
    private ?array $rates = null;

    private function rate(string $boothId, string $day): ?int
    {
        if ($this->rates === null) {
            $this->rates = [];
            foreach ($this->statements->rows(
                "SELECT booth_id::text AS booth_id, to_char(month_start, 'YYYY-MM-DD') AS month_start, amount_minor
                 FROM booth_rent_rates
                 ORDER BY month_start"
            ) as $row) {
                $this->rates[RowValue::string($row['booth_id'] ?? null)][] = [
                    'start' => RowValue::string($row['month_start'] ?? null),
                    'amount' => RowValue::integer($row['amount_minor'] ?? null),
                ];
            }
        }
        $amount = null;
        foreach ($this->rates[$boothId] ?? [] as $rate) {
            if ($rate['start'] <= $day) {
                $amount = $rate['amount'];
            }
        }

        return $amount;
    }

    private function override(mixed $amount): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return RowValue::integer($amount);
    }
}
