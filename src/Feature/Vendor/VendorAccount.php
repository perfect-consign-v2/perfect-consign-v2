<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class VendorAccount
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return array{payable: int, rent: int}
     */
    public function numbers(string $vendorId): array
    {
        return [
            'payable' => $this->sum(
                "SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) AS total
                 FROM journal_lines AS line
                 JOIN chart_roles AS role ON role.account_id = line.account_id
                 WHERE role.role = 'vendor_payable'
                   AND line.subledger_type = 'vendor'
                   AND line.subledger_ref = ?",
                [$vendorId]
            ),
            'rent' => $this->sum(
                "SELECT COALESCE(sum(booth.open_minor), 0) AS total
                 FROM (
                     SELECT sum(line.debit_minor - line.credit_minor) AS open_minor
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
                 ) AS booth",
                [$vendorId]
            ),
        ];
    }

    /**
     * @return list<array{id: string, code: string, description: string, price_minor: int, quantity: int, sold_on: string}>
     */
    public function items(string $vendorId): array
    {
        $rows = [];
        foreach ($this->statements->bound(
            "SELECT id::text AS id, code, description, price_minor, quantity,
                    CASE
                        WHEN quantity = 0 THEN (
                            SELECT to_char(journal.posting_date, 'YYYY-MM-DD')
                            FROM sale_lines AS sale
                            JOIN journals AS journal ON journal.id = sale.journal_id
                            WHERE sale.item_id = items.id
                              AND journal.source_type = 'sale'
                              AND NOT journal.is_reversal
                              AND NOT EXISTS (
                                  SELECT 1 FROM journals AS reversal
                                  WHERE reversal.reverses_journal_id = journal.id
                              )
                            ORDER BY journal.posting_date DESC, journal.occurred_at DESC
                            LIMIT 1
                        )
                    END AS sold_on
             FROM items
             WHERE vendor_id = ?::uuid",
            [$vendorId]
        ) as $row) {
            $sold = RowValue::optionalString($row['sold_on'] ?? null);
            $rows[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'code' => RowValue::string($row['code'] ?? null),
                'description' => RowValue::string($row['description'] ?? null),
                'price_minor' => RowValue::integer($row['price_minor'] ?? null),
                'quantity' => RowValue::integer($row['quantity'] ?? null),
                'sold_on' => $sold ?? '',
            ];
        }
        usort(
            $rows,
            static function (array $left, array $right): int {
                $leftOnHand = $left['quantity'] > 0;
                $rightOnHand = $right['quantity'] > 0;
                if ($leftOnHand !== $rightOnHand) {
                    return $leftOnHand ? -1 : 1;
                }

                return strnatcasecmp($left['code'], $right['code']);
            }
        );

        return $rows;
    }

    public function owns(string $vendorId, string $itemId): bool
    {
        $rows = $this->statements->bound(
            'SELECT id FROM items WHERE id = ?::uuid AND vendor_id = ?::uuid',
            [$itemId, $vendorId]
        );

        return $rows !== [];
    }

    /**
     * @param list<mixed> $params
     */
    private function sum(string $sql, array $params): int
    {
        $rows = $this->statements->bound($sql, $params);

        return RowValue::integer($rows[0]['total'] ?? 0);
    }
}
