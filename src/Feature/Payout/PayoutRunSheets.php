<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class PayoutRunSheets
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, sold_on: string, item_code: string, description: string, price: int, quantity: int, commission: int, rent: int, check: int, owes: int, check_number: string}>
     */
    public function envelopes(
        string $runId,
        string $salesFrom = '',
        string $salesTo = '',
        string $rentMonth = '',
        string $postingDate = '',
    ): array {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $runId) === 1) {
            $stored = $this->stored($runId);
            if ($stored !== []) {
                return $stored;
            }

            return $this->open($runId);
        }
        if ($this->day($salesFrom) === '' || $this->day($salesTo) === '' || $this->day($rentMonth) === '' || $this->day($postingDate) === '') {
            return [];
        }

        return $this->preview($salesFrom, $salesTo, $rentMonth, $postingDate);
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, sold_on: string, item_code: string, description: string, price: int, quantity: int, commission: int, rent: int, check: int, owes: int, check_number: string}>
     */
    public function stored(string $runId): array
    {
        return $this->sheets($this->storedSheets(), [$runId]);
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, sold_on: string, item_code: string, description: string, price: int, quantity: int, commission: int, rent: int, check: int, owes: int, check_number: string}>
     */
    public function open(string $runId): array
    {
        return $this->sheets($this->openSheets(), [$runId]);
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, sold_on: string, item_code: string, description: string, price: int, quantity: int, commission: int, rent: int, check: int, owes: int, check_number: string}>
     */
    public function preview(string $salesFrom, string $salesTo, string $rentMonth, string $postingDate): array
    {
        return $this->sheets($this->previewSheets(), [$salesFrom, $salesTo, $rentMonth, $postingDate, $salesFrom, $salesTo]);
    }

    private function day(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    /**
     * @param list<mixed> $params
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, sold_on: string, item_code: string, description: string, price: int, quantity: int, commission: int, rent: int, check: int, owes: int, check_number: string}>
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
                'quantity' => RowValue::integer($row['quantity'] ?? 0),
                'commission' => RowValue::integer($row['commission_minor'] ?? 0),
                'rent' => RowValue::integer($row['settled_minor'] ?? 0),
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
                       COALESCE(sale.quantity, 0) AS quantity,
                       COALESCE(sale.commission_minor, 0) AS commission_minor,
                       COALESCE(NULLIF(line.settled_minor, 0), (
                           SELECT journal.debit_total_minor
                           FROM journals AS journal
                           WHERE journal.id = line.settlement_journal_id
                             AND NOT journal.is_reversal
                       ), (
                           SELECT journal.debit_total_minor
                           FROM payout_run_journals AS link
                           JOIN journals AS journal ON journal.id = link.journal_id
                           WHERE link.run_id = line.run_id
                             AND link.role = 'settlement'
                             AND NOT journal.is_reversal
                             AND journal.posting_key = 'settlement:' || line.run_id::text || ':' || line.vendor_id::text
                       ), 0) AS settled_minor,
                       COALESCE(NULLIF(line.check_minor, 0), (
                           SELECT journal.debit_total_minor
                           FROM journals AS journal
                           WHERE journal.id = line.disbursement_journal_id
                             AND NOT journal.is_reversal
                       ), (
                           SELECT journal.debit_total_minor
                           FROM payout_run_journals AS link
                           JOIN journals AS journal ON journal.id = link.journal_id
                           WHERE link.run_id = line.run_id
                             AND link.role = 'disbursement'
                             AND NOT journal.is_reversal
                             AND journal.posting_key = 'disbursement:' || line.run_id::text || ':' || line.vendor_id::text
                       ), 0) AS check_minor,
                       line.owes_minor, COALESCE(line.check_number, '') AS check_number
                FROM payout_run_lines AS line
                JOIN vendors AS vendor ON vendor.id = line.vendor_id
                LEFT JOIN LATERAL (
                    SELECT stored.sold_on, stored.item_code, stored.description, stored.price_minor,
                           stored.quantity, stored.commission_minor, stored.line_no
                    FROM payout_run_sales AS stored
                    WHERE stored.run_id = line.run_id AND stored.vendor_id = line.vendor_id
                    UNION ALL
                    SELECT pack.sold_on, pack.item_code, pack.description, pack.price_minor,
                           pack.quantity, pack.commission_minor, pack.line_no
                    FROM payout_pack_sales AS pack
                    WHERE line.sales_minor = 0
                      AND line.check_minor = 0
                      AND line.settled_minor = 0
                      AND NOT EXISTS (
                          SELECT 1 FROM payout_run_sales AS stored
                          WHERE stored.run_id = line.run_id AND stored.vendor_id = line.vendor_id
                      )
                      AND pack.vendor_id = line.vendor_id
                      AND pack.run_id = (
                          SELECT batch.id
                          FROM payout_runs AS batch
                          WHERE batch.kind = 'batch'
                            AND batch.status = 'draft'
                            AND batch.reverses_run_id IS NULL
                          ORDER BY batch.run_no DESC
                          LIMIT 1
                      )
                ) AS sale ON true
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
                       COALESCE(sale.quantity, 0) AS quantity,
                       COALESCE(sale.commission_minor, 0) AS commission_minor,
                       pack.settled_minor, pack.check_minor, pack.owes_minor, '' AS check_number
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
                       COALESCE(sale.quantity, 0) AS quantity,
                       COALESCE(sale.commission_minor, 0) AS commission_minor,
                       pack.settled_minor, pack.check_minor, pack.owes_minor, '' AS check_number
                FROM stall_payout_pack(NULL::uuid, ?::date, ?::date, ?::date, ?::date) AS pack
                LEFT JOIN LATERAL (
                    SELECT journal.posting_date AS sold_on, item.code AS item_code, item.description,
                           sale.price_minor, sale.quantity, sale.commission_minor, sale.line_no
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
}
