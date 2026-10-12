<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PDOException;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class PayoutRunStore
{
    public function __construct(
        private readonly Statements $statements,
        private readonly OccupiedPack $occupied,
    ) {
    }

    /**
     * @param list<array{vendor_id: string, code: string, name: string, booth: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, check: int, owes: int, settled: int, adjustment: int}> $pack
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, check: int, owes: int, settled: int, adjustment: int}>
     */
    public function addBooths(array $pack, string $today): array
    {
        $filled = $this->occupied->fill($pack, $today);
        usort(
            $filled,
            static fn (array $left, array $right): int => strnatcasecmp($left['code'], $right['code'])
        );

        return $filled;
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, check: int, owes: int, settled: int, adjustment: int}>
     */
    public function pack(string $runId): array
    {
        return $this->lines(
            'SELECT vendor_id, code, name, booth, mode, prior, charged, paid, payable, rent,
                    "check" AS check_amount, owes AS owe_amount, settled_minor, adjustment_minor
             FROM payout_pack WHERE run_id = ?::uuid AND mode = ?
             ORDER BY code',
            [$runId, 'pay']
        );
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, check: int, owes: int, settled: int, adjustment: int}>
     */
    public function stored(string $runId): array
    {
        return $this->lines(
            "SELECT line.vendor_id, vendor.code,
                    btrim(vendor.first_name || ' ' || vendor.last_name) AS name,
                    '' AS booth, line.mode,
                    line.rent_brought_forward_minor AS prior,
                    line.rent_charged_minor + line.rent_minor AS charged,
                    line.rent_paid_since_minor + line.settlement_applied_minor AS paid,
                    line.carried_payable_minor AS payable,
                    line.carried_rent_minor AS rent,
                    line.check_minor AS check_amount,
                    line.owes_minor AS owe_amount,
                    line.settled_minor,
                    line.adjustment_minor
             FROM payout_run_lines AS line
             JOIN vendors AS vendor ON vendor.id = line.vendor_id
             WHERE line.run_id = ?::uuid
             ORDER BY vendor.code",
            [$runId]
        );
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
            'SELECT run.id, run.run_no, run.sales_from, run.sales_to, run.rent_month, run.tender, run.status,
                    run.reversed_at, reversal.reversed_by AS undone_by, reversal.reversal_reason AS undone_reason
             FROM payout_runs AS run
             LEFT JOIN LATERAL (
                 SELECT reversed_by, reversal_reason
                 FROM payout_runs
                 WHERE reverses_run_id = run.id
                 ORDER BY run_no DESC
                 LIMIT 1
             ) AS reversal ON true
             WHERE run.reverses_run_id IS NULL
             ORDER BY run.run_no DESC'
        );
    }

    public function openDraft(string $salesFrom, string $salesTo, string $rentMonth): ?string
    {
        $rows = $this->statements->bound(
            "SELECT id FROM payout_runs
             WHERE status = 'draft' AND kind = 'batch' AND reverses_run_id IS NULL
               AND sales_from = ?::date AND sales_to = ?::date AND rent_month = ?::date
             ORDER BY created_at DESC LIMIT 1",
            [$salesFrom, $salesTo, $rentMonth]
        );
        if ($rows === []) {
            return null;
        }

        return RowValue::string($rows[0]['id'] ?? null);
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, check: int, owes: int, settled: int, adjustment: int}>
     */
    public function preview(string $salesFrom, string $salesTo, string $rentMonth, string $postingDate): array
    {
        return $this->lines(
            'SELECT vendor_id, code, name, booth, mode, prior, charged, paid, payable, rent,
                    "check" AS check_amount, owes AS owe_amount, settled_minor, adjustment_minor
             FROM stall_payout_pack(NULL::uuid, ?::date, ?::date, ?::date, ?::date)
             WHERE mode = ?
             ORDER BY code',
            [$salesFrom, $salesTo, $rentMonth, $postingDate, 'pay']
        );
    }

    public function ensureDraft(
        string $salesFrom,
        string $salesTo,
        string $rentMonth,
        string $postingDate,
        string $tender = 'check',
    ): string {
        $tender = $this->tender($tender);
        $existing = $this->openDraft($salesFrom, $salesTo, $rentMonth);
        if ($existing !== null) {
            $this->stamp($existing, $postingDate, $tender);

            return $existing;
        }
        try {
            return $this->begin($salesFrom, $salesTo, $rentMonth, $postingDate, $tender);
        } catch (PDOException $error) {
            if ($error->getCode() !== '23505') {
                throw $error;
            }
            $winner = $this->openDraft($salesFrom, $salesTo, $rentMonth);
            if ($winner === null) {
                throw $error;
            }
            $this->stamp($winner, $postingDate, $tender);

            return $winner;
        }
    }

    public function review(string $runId): void
    {
        $this->statements->change(
            "UPDATE payout_runs
             SET reviewed_checksum = stall_pack_checksum(id), reviewed_at = clock_timestamp()
             WHERE id = ?::uuid AND status = 'draft'",
            [$runId]
        );
    }

    /**
     * @return array{tender: string, status: string, posting_date: string, rent_month: string}
     */
    public function run(string $runId): array
    {
        $rows = $this->statements->bound(
            "SELECT tender, status,
                    COALESCE(to_char(posting_date, 'YYYY-MM-DD'), '') AS posting_date,
                    COALESCE(to_char(rent_month, 'YYYY-MM-DD'), '') AS rent_month
             FROM payout_runs WHERE id = ?::uuid",
            [$runId]
        );

        return [
            'tender' => RowValue::string($rows[0]['tender'] ?? null),
            'status' => RowValue::string($rows[0]['status'] ?? null),
            'posting_date' => RowValue::string($rows[0]['posting_date'] ?? null),
            'rent_month' => RowValue::string($rows[0]['rent_month'] ?? null),
        ];
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, sold_on: string, item_code: string, description: string, price: int, commission: int, tax: int, check: int, owes: int, check_number: string}>
     */
    public function envelopes(
        string $runId,
        string $salesFrom = '',
        string $salesTo = '',
        string $rentMonth = '',
        string $postingDate = '',
    ): array {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $runId) === 1) {
            $stored = $this->sheets($this->storedSheets(), [$runId]);
            if ($stored !== []) {
                return $stored;
            }

            return $this->sheets($this->openSheets(), [$runId]);
        }
        if ($this->day($salesFrom) === '' || $this->day($salesTo) === '' || $this->day($rentMonth) === '' || $this->day($postingDate) === '') {
            return [];
        }

        return $this->sheets($this->previewSheets(), [$salesFrom, $salesTo, $rentMonth, $postingDate, $salesFrom, $salesTo]);
    }

    /**
     * @param list<mixed> $params
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, sold_on: string, item_code: string, description: string, price: int, commission: int, tax: int, check: int, owes: int, check_number: string}>
     */
    private function sheets(string $sql, array $params): array
    {
        $sheets = [];
        foreach ($this->statements->bound($sql, $params) as $row) {
            $number = $row['check_number'] ?? null;
            $sheets[] = [
                'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
                'code' => RowValue::string($row['code'] ?? null),
                'name' => RowValue::string($row['name'] ?? null),
                'booth' => RowValue::string($row['booth'] ?? null),
                'sold_on' => RowValue::string($row['sold_on'] ?? null),
                'item_code' => RowValue::string($row['item_code'] ?? null),
                'description' => RowValue::string($row['description'] ?? null),
                'price' => RowValue::integer($row['price_minor'] ?? 0),
                'commission' => RowValue::integer($row['commission_minor'] ?? 0),
                'tax' => RowValue::integer($row['tax_minor'] ?? 0),
                'check' => RowValue::integer($row['check_minor'] ?? null),
                'owes' => RowValue::integer($row['owes_minor'] ?? null),
                'check_number' => is_string($number) ? $number : '',
            ];
        }

        return $sheets;
    }

    private function storedSheets(): string
    {
        return "SELECT line.vendor_id::text AS vendor_id, vendor.code,
                       btrim(vendor.first_name || ' ' || vendor.last_name) AS name,
                       COALESCE(spot.booth, '') AS booth,
                       COALESCE(to_char(sale.sold_on, 'YYYY-MM-DD'), '') AS sold_on,
                       COALESCE(sale.item_code, '') AS item_code,
                       COALESCE(sale.description, '') AS description,
                       COALESCE(sale.price_minor, 0) AS price_minor,
                       COALESCE(sale.commission_minor, 0) AS commission_minor,
                       COALESCE(sale.tax_minor, 0) AS tax_minor,
                       line.check_minor, line.owes_minor, COALESCE(line.check_number, '') AS check_number
                FROM payout_run_lines AS line
                JOIN vendors AS vendor ON vendor.id = line.vendor_id
                LEFT JOIN payout_run_sales AS sale ON sale.run_id = line.run_id AND sale.vendor_id = line.vendor_id
                LEFT JOIN LATERAL (
                    SELECT booth.code AS booth
                    FROM payout_runs AS run
                    JOIN booth_assignments AS assignment
                      ON assignment.vendor_id = line.vendor_id
                     AND assignment.assignment_range @> run.posting_date
                    JOIN booths AS booth ON booth.id = assignment.booth_id
                    WHERE run.id = line.run_id
                    ORDER BY booth.code
                    LIMIT 1
                ) AS spot ON true
                WHERE line.run_id = ?::uuid AND line.mode = 'pay'
                ORDER BY vendor.code, sale.sold_on, sale.line_no";
    }

    private function openSheets(): string
    {
        return "SELECT pack.vendor_id::text AS vendor_id, pack.code, pack.name, pack.booth,
                       COALESCE(to_char(sale.sold_on, 'YYYY-MM-DD'), '') AS sold_on,
                       COALESCE(sale.item_code, '') AS item_code,
                       COALESCE(sale.description, '') AS description,
                       COALESCE(sale.price_minor, 0) AS price_minor,
                       COALESCE(sale.commission_minor, 0) AS commission_minor,
                       COALESCE(sale.tax_minor, 0) AS tax_minor,
                       pack.check_minor, pack.owes_minor, '' AS check_number
                FROM payout_pack AS pack
                LEFT JOIN payout_pack_sales AS sale
                  ON sale.run_id = pack.run_id AND sale.vendor_id = pack.vendor_id
                WHERE pack.run_id = ?::uuid AND pack.mode = 'pay'
                ORDER BY pack.code, sale.sold_on, sale.line_no";
    }

    private function previewSheets(): string
    {
        return "SELECT pack.vendor_id::text AS vendor_id, pack.code, pack.name, pack.booth,
                       COALESCE(to_char(sale.sold_on, 'YYYY-MM-DD'), '') AS sold_on,
                       COALESCE(sale.item_code, '') AS item_code,
                       COALESCE(sale.description, '') AS description,
                       COALESCE(sale.price_minor, 0) AS price_minor,
                       COALESCE(sale.commission_minor, 0) AS commission_minor,
                       COALESCE(sale.tax_minor, 0) AS tax_minor,
                       pack.check_minor, pack.owes_minor, '' AS check_number
                FROM stall_payout_pack(NULL::uuid, ?::date, ?::date, ?::date, ?::date) AS pack
                LEFT JOIN LATERAL (
                    SELECT journal.posting_date AS sold_on, item.code AS item_code, item.description,
                           sale.price_minor, sale.commission_minor, sale.tax_minor, sale.line_no
                    FROM journals AS journal
                    JOIN sale_lines AS sale ON sale.journal_id = journal.id
                    JOIN items AS item ON item.id = sale.item_id AND item.vendor_id = pack.vendor_id
                    WHERE journal.posting_date BETWEEN ?::date AND ?::date
                      AND journal.source_type = 'sale'
                      AND NOT journal.is_reversal
                ) AS sale ON true
                WHERE pack.mode = 'pay'
                ORDER BY pack.code, sale.sold_on, sale.line_no";
    }

    private function day(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    private function tender(string $tender): string
    {
        if ($tender === 'drawer_cash' || $tender === 'safe_cash' || $tender === 'check') {
            return $tender;
        }

        return 'check';
    }

    private function stamp(string $runId, string $postingDate, string $tender): void
    {
        $this->statements->change(
            "UPDATE payout_runs
             SET posting_date = ?::date, tender = ?
             WHERE id = ?::uuid AND status = 'draft'",
            [$postingDate, $tender, $runId]
        );
    }

    private function begin(
        string $salesFrom,
        string $salesTo,
        string $rentMonth,
        string $postingDate,
        string $tender,
    ): string {
        $rows = $this->statements->bound(
            "INSERT INTO payout_runs (
                book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
             )
             SELECT id, 'batch', 'monthly', ?::date, ?::date, ?::date, ?, 'draft', ?::date
             FROM ledger_books
             WHERE is_default
             RETURNING id",
            [$salesFrom, $salesTo, $rentMonth, $tender, $postingDate]
        );

        return RowValue::string($rows[0]['id'] ?? null);
    }

    /**
     * @param list<mixed> $params
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, check: int, owes: int, settled: int, adjustment: int}>
     */
    private function lines(string $sql, array $params): array
    {
        $pack = [];
        foreach ($this->statements->bound($sql, $params) as $row) {
            $pack[] = [
                'vendor_id' => $this->text($row, 'vendor_id'),
                'code' => $this->text($row, 'code'),
                'name' => $this->text($row, 'name'),
                'booth' => $this->text($row, 'booth'),
                'mode' => $this->text($row, 'mode'),
                'prior' => RowValue::integer($row['prior'] ?? null),
                'charged' => RowValue::integer($row['charged'] ?? null),
                'paid' => RowValue::integer($row['paid'] ?? null),
                'payable' => RowValue::integer($row['payable'] ?? null),
                'rent' => RowValue::integer($row['rent'] ?? null),
                'check' => RowValue::integer($row['check_amount'] ?? null),
                'owes' => RowValue::integer($row['owe_amount'] ?? null),
                'settled' => RowValue::integer($row['settled_minor'] ?? null),
                'adjustment' => RowValue::integer($row['adjustment_minor'] ?? null),
            ];
        }

        return $pack;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException($key . ' ' . get_debug_type($value));
        }

        return $value;
    }
}
