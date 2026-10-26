<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use DateTimeImmutable;
use PDO;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class PayoutStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Statements $statements,
        private readonly PayoutRunStore $runs,
        private readonly PayoutWindow $window,
    ) {
    }

    /**
     * Ledger balance used for a draw and for the check written after settlement.
     *
     * @return array{payable: int}
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

        return ['payable' => RowValue::integer($payable->fetchColumn())];
    }

    /**
     * The batch pack row for this vendor. Pay vendors and Pay this vendor both read it.
     *
     * @return array{run_id: string, payable: int, rent: int, check: int, owes: int, settled: int, rent_from_sales: bool}|null
     */
    public function quote(string $vendorId, string $today): ?array
    {
        $window = $this->window->window($today, $this->cycleDay());
        $runId = $this->runs->openDraft($window['from'], $window['to'], $window['rent']);
        if ($runId === null) {
            $rows = $this->statements->bound(
                'SELECT payable, rent, "check" AS check_amount, owes AS owe_amount, settled_minor, rent_from_sales
                 FROM stall_payout_quote(?::uuid, ?::date, ?::date, ?::date, ?::date)',
                [$vendorId, $window['from'], $window['to'], $window['rent'], $today]
            );
        } else {
            $rows = $this->statements->bound(
                'SELECT payable, rent, "check" AS check_amount, owes AS owe_amount, settled_minor, rent_from_sales
                 FROM stall_payout_run_quote(?::uuid, ?::uuid)',
                [$runId, $vendorId]
            );
        }
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return null;
        }
        $fromSales = $row['rent_from_sales'] ?? null;

        return [
            'run_id' => $runId ?? '',
            'payable' => RowValue::integer($row['payable'] ?? null),
            'rent' => RowValue::integer($row['rent'] ?? null),
            'check' => RowValue::integer($row['check_amount'] ?? null),
            'owes' => RowValue::integer($row['owe_amount'] ?? null),
            'settled' => RowValue::integer($row['settled_minor'] ?? null),
            'rent_from_sales' => $fromSales === true || $fromSales === 1 || $fromSales === '1' || $fromSales === 't' || $fromSales === 'true',
        ];
    }

    /**
     * The posting command opens the draft, then reads that saved pack.
     *
     * @return array{run_id: string, payable: int, rent: int, check: int, owes: int, settled: int, rent_from_sales: bool}|null
     */
    public function quotedRun(string $vendorId, string $today): ?array
    {
        $window = $this->window->window($today, $this->cycleDay());
        $this->runs->ensureDraft($window['from'], $window['to'], $window['rent'], $today);

        return $this->quote($vendorId, $today);
    }

    /**
     * Uncharged rent rows the pack already counted.
     *
     * @return list<array{booth_id: string, posting_date: string, amount: int, month: string}>
     */
    public function missingCharges(string $runId, string $vendorId): array
    {
        $charges = [];
        foreach ($this->statements->bound(
            "SELECT booth_id::text AS booth_id,
                    to_char(posting_date, 'YYYY-MM-DD') AS posting_date,
                    amount,
                    stall_rent_charge_suffix(month_start) AS month
             FROM payout_missing_rent
             WHERE run_id = ?::uuid AND vendor_id = ?::uuid AND amount > 0
             ORDER BY month_start, booth_id",
            [$runId, $vendorId]
        ) as $row) {
            $charges[] = [
                'booth_id' => RowValue::string($row['booth_id'] ?? null),
                'posting_date' => RowValue::string($row['posting_date'] ?? null),
                'amount' => RowValue::integer($row['amount'] ?? null),
                'month' => RowValue::string($row['month'] ?? null),
            ];
        }

        return $charges;
    }

    /**
     * Uncharged rent the open payout already takes from sales. No draft means nothing is taken yet.
     *
     * @return list<array{booth_id: string, code: string, vendor_name: ?string, month: string, label: string, amount_minor: int, charge_date: string}>
     */
    public function salesRent(string $today): array
    {
        $window = $this->window->window($today, $this->cycleDay());
        $runId = $this->runs->openDraft($window['from'], $window['to'], $window['rent']);
        if ($runId === null) {
            return [];
        }
        $taken = [];
        foreach ($this->statements->bound(
            "SELECT missing.booth_id::text AS booth_id, booth.code,
                    btrim(vendor.first_name || ' ' || vendor.last_name) AS vendor_name,
                    stall_rent_charge_suffix(missing.month_start) AS month, missing.amount
             FROM payout_missing_rent AS missing
             JOIN booths AS booth ON booth.id = missing.booth_id
             JOIN vendors AS vendor ON vendor.id = missing.vendor_id
             WHERE missing.run_id = ?::uuid AND missing.amount > 0
             ORDER BY month, booth.code",
            [$runId]
        ) as $row) {
            $month = RowValue::string($row['month'] ?? null);
            $name = RowValue::string($row['vendor_name'] ?? null);
            $labelOn = strlen($month) === 7 ? $month . '-01' : $month;
            $taken[] = [
                'booth_id' => RowValue::string($row['booth_id'] ?? null),
                'code' => RowValue::string($row['code'] ?? null),
                'vendor_name' => $name === '' ? null : $name,
                'month' => $month,
                'label' => (new DateTimeImmutable($labelOn))->format(strlen($month) === 7 ? 'F Y' : 'M j, Y'),
                'amount_minor' => RowValue::integer($row['amount'] ?? null),
                'charge_date' => '',
            ];
        }

        return $taken;
    }

    /**
     * Vendors the open payout says owe the store. Same `owes` the vendor page shows.
     *
     * @return list<array{vendor_id: string, code: string, name: string, booth_id: string, booth: string, owes: int, collect: string}>
     */
    public function debtors(string $today): array
    {
        $window = $this->window->window($today, $this->cycleDay());
        $runId = $this->runs->openDraft($window['from'], $window['to'], $window['rent']);
        $sql = $runId === null
            ? "SELECT pack.vendor_id::text AS vendor_id, pack.code, pack.name, pack.booth, pack.owes,
                      pack.booth_id::text AS booth_id
               FROM stall_payout_pack(NULL::uuid, ?::date, ?::date, ?::date, ?::date) AS pack
               WHERE pack.owes > 0
               ORDER BY pack.code"
            : "SELECT pack.vendor_id::text AS vendor_id, pack.code, pack.name, pack.booth, pack.owes,
                      pack.booth_id::text AS booth_id
               FROM payout_pack AS pack
               WHERE pack.run_id = ?::uuid AND pack.owes > 0
               ORDER BY pack.code";
        $params = $runId === null
            ? [$window['from'], $window['to'], $window['rent'], $today]
            : [$runId];
        /** @var list<array{vendor_id: string, code: string, name: string, booth_id: string, booth: string, owes: int, collect: string}> $owed */
        $owed = [];
        foreach ($this->statements->bound($sql, $params) as $row) {
            $boothId = $row['booth_id'] ?? null;
            $amount = RowValue::integer($row['owes'] ?? null);
            $owed[] = [
                'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
                'code' => RowValue::string($row['code'] ?? null),
                'name' => RowValue::string($row['name'] ?? null),
                'booth_id' => is_string($boothId) ? $boothId : '',
                'booth' => RowValue::string($row['booth'] ?? null),
                'owes' => $amount,
                'collect' => intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT),
            ];
        }
        usort(
            $owed,
            static fn (array $left, array $right): int => strnatcasecmp($left['code'], $right['code'])
        );

        return $owed;
    }

    /**
     * Positive booth rent that can take the settlement credit. The amount settled is the pack figure.
     *
     * @return list<array{booth_id: string, open_minor: int}>
     */
    public function boothRent(string $vendorId): array
    {
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
             HAVING sum(line.debit_minor - line.credit_minor) > 0
             ORDER BY line.subledger_ref"
        );
        $rent->execute([$vendorId]);
        $booths = [];
        foreach ($this->statements->all($rent) as $row) {
            $booths[] = [
                'booth_id' => RowValue::string($row['booth_id'] ?? null),
                'open_minor' => RowValue::integer($row['open_minor'] ?? null),
            ];
        }

        return $booths;
    }

    private function cycleDay(): int
    {
        $rows = $this->statements->rows('SELECT rent_cycle_day FROM mall');
        $day = RowValue::integer($rows[0]['rent_cycle_day'] ?? null);

        return $day >= 1 && $day <= 28 ? $day : 1;
    }

}
