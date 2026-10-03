<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class PayoutRunStore
{
    public function __construct(
        private readonly Statements $statements,
        private readonly PayoutStore $payouts,
    ) {
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, prior: int, charged: int, paid: int, payable: int, rent: int, check: int, owes: int}>
     */
    public function pack(string $rentMonth, string $today): array
    {
        $rows = $this->statements->bound(
            "SELECT vendor.id, vendor.code, vendor.first_name, vendor.last_name,
                    COALESCE(spot.booth_code, '') AS booth_code,
                    COALESCE(payable.open_minor, 0) AS payable,
                    COALESCE(rent.prior_minor, 0) AS prior,
                    COALESCE(rent.charged_minor, 0) AS charged,
                    COALESCE(rent.paid_minor, 0) AS paid
             FROM vendors AS vendor
             LEFT JOIN LATERAL (
                 SELECT booth.code AS booth_code
                 FROM booth_assignments AS assignment
                 JOIN booths AS booth ON booth.id = assignment.booth_id
                 WHERE assignment.vendor_id = vendor.id
                   AND assignment.assignment_range @> CURRENT_DATE
                 ORDER BY booth.code
                 LIMIT 1
             ) AS spot ON true
             LEFT JOIN (
                 SELECT line.subledger_ref AS vendor_id, sum(line.credit_minor - line.debit_minor) AS open_minor
                 FROM journal_lines AS line
                 JOIN chart_roles AS role ON role.account_id = line.account_id
                 WHERE role.role = 'vendor_payable' AND line.subledger_type = 'vendor'
                 GROUP BY line.subledger_ref
             ) AS payable ON payable.vendor_id = vendor.id::text
             LEFT JOIN (
                 SELECT assignment.vendor_id,
                        sum(CASE WHEN posted.posting_date < ?::date THEN line.debit_minor - line.credit_minor ELSE 0 END) AS prior_minor,
                        sum(CASE WHEN posted.posting_date >= ?::date AND posted.posting_date < (?::date + interval '1 month') AND line.debit_minor > 0 THEN line.debit_minor ELSE 0 END) AS charged_minor,
                        sum(CASE WHEN posted.posting_date >= ?::date AND posted.posting_date < (?::date + interval '1 month') THEN line.credit_minor ELSE 0 END) AS paid_minor
                 FROM journal_lines AS line
                 JOIN journals AS posted ON posted.id = line.journal_id
                 JOIN chart_roles AS role ON role.account_id = line.account_id
                 JOIN booth_assignments AS assignment
                   ON assignment.booth_id = line.subledger_ref::uuid
                  AND assignment.assignment_range @> posted.posting_date
                 WHERE role.role = 'booth_rent_receivable' AND line.subledger_type = 'booth'
                 GROUP BY assignment.vendor_id
             ) AS rent ON rent.vendor_id = vendor.id
             WHERE vendor.active AND vendor.payout_mode = 'pay'
             ORDER BY spot.booth_code NULLS LAST, vendor.code",
            [$rentMonth, $rentMonth, $rentMonth, $rentMonth, $rentMonth]
        );
        $missing = $this->payouts->missingByVendor($today);
        $month = substr($rentMonth, 0, 7);
        $pack = [];
        foreach ($rows as $row) {
            $pack[] = $this->row($row, $missing, $month);
        }

        return $pack;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, list<array{booth_id: string, posting_date: string, amount: int, month: string}>> $missing
     * @return array{vendor_id: string, code: string, name: string, booth: string, prior: int, charged: int, paid: int, payable: int, rent: int, check: int, owes: int}
     */
    private function row(array $row, array $missing, string $month): array
    {
        $vendorId = RowValue::string($row['id'] ?? null);
        $payable = RowValue::integer($row['payable'] ?? null);
        $prior = RowValue::integer($row['prior'] ?? null);
        $charged = RowValue::integer($row['charged'] ?? null);
        $paid = RowValue::integer($row['paid'] ?? null);
        foreach ($missing[$vendorId] ?? [] as $charge) {
            if ($charge['month'] < $month) {
                $prior += $charge['amount'];
            }
            if ($charge['month'] === $month) {
                $charged += $charge['amount'];
            }
        }
        $rent = $prior + $charged - $paid;

        return [
            'vendor_id' => $vendorId,
            'code' => RowValue::string($row['code'] ?? null),
            'name' => trim(RowValue::string($row['first_name'] ?? null) . ' ' . RowValue::string($row['last_name'] ?? null)),
            'booth' => RowValue::string($row['booth_code'] ?? null),
            'prior' => $prior,
            'charged' => $charged,
            'paid' => $paid,
            'payable' => $payable,
            'rent' => $rent,
            'check' => max(0, $payable - $rent),
            'owes' => max(0, $rent - $payable),
        ];
    }

    /**
     * @return list<array{code: string, name: string, mode: string}>
     */
    public function excluded(): array
    {
        $rows = $this->statements->rows(
            "SELECT code, first_name, last_name, payout_mode
             FROM vendors
             WHERE active AND payout_mode IN ('credit_only', 'hold')
             ORDER BY code"
        );
        $excluded = [];
        foreach ($rows as $row) {
            $excluded[] = [
                'code' => RowValue::string($row['code'] ?? null),
                'name' => trim(RowValue::string($row['first_name'] ?? null) . ' ' . RowValue::string($row['last_name'] ?? null)),
                'mode' => RowValue::string($row['payout_mode'] ?? null),
            ];
        }

        return $excluded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(): array
    {
        return $this->statements->rows(
            'SELECT id, run_no, sales_from, sales_to, rent_month, tender, status
             FROM payout_runs
             ORDER BY run_no DESC'
        );
    }

    public function begin(string $salesFrom, string $salesTo, string $rentMonth, string $postingDate): string
    {
        $rows = $this->statements->bound(
            "INSERT INTO payout_runs (
                book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
             )
             SELECT id, 'batch', 'monthly', ?::date, ?::date, ?::date, 'check', 'draft', ?::date
             FROM ledger_books
             WHERE is_default
             RETURNING id",
            [$salesFrom, $salesTo, $rentMonth, $postingDate]
        );

        return RowValue::string($rows[0]['id'] ?? null);
    }

    public function remember(string $runId, string $vendorId, int $check, int $owes, ?string $settlementId): void
    {
        $this->statements->change(
            'INSERT INTO payout_run_lines (
                run_id, vendor_id, mode, check_minor, owes_minor, settlement_journal_id
             ) VALUES (?::uuid, ?::uuid, ?, ?, ?, ?::uuid)',
            [$runId, $vendorId, 'pay', $check, $owes, $settlementId]
        );
    }

    public function markSettled(string $runId): void
    {
        $this->statements->change(
            "UPDATE payout_runs SET status = 'settled', settled_at = clock_timestamp()
             WHERE id = ?::uuid AND status = 'draft'",
            [$runId]
        );
    }

    public function markPaid(string $runId): void
    {
        $this->statements->change(
            "UPDATE payout_runs SET status = 'paid', paid_at = clock_timestamp()
             WHERE id = ?::uuid AND status = 'settled'",
            [$runId]
        );
    }
}
