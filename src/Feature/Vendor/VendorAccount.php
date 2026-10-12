<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectConsign\Feature\Inventory\InventoryStore;
use PerfectConsign\Feature\Inventory\ItemForm;
use PerfectConsign\Feature\Inventory\StoreTake;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class VendorAccount
{
    public function __construct(
        private readonly Statements $statements,
        private readonly InventoryStore $inventory,
        private readonly ItemForm $forms,
        private readonly StoreTake $take,
    ) {
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
     * @return list<array<string, mixed>>
     */
    public function items(string $vendorId, string $query, string $stock): array
    {
        $rows = [];
        foreach ($this->inventory->items($query, $stock, $vendorId) as $item) {
            $expires = $item['expires_on'] ?? null;
            $brand = $item['brand_name'] ?? null;
            $item['barcode'] = RowValue::string($item['barcode'] ?? null);
            $item['quantity'] = RowValue::integer($item['quantity'] ?? null);
            $item['intake_on'] = RowValue::string($item['intake_on'] ?? null);
            $item['expires_on'] = is_string($expires) ? $expires : '';
            $item['take_label'] = $this->take->format(RowValue::integer($item['take_bps'] ?? null));
            $item['overridden'] = ($item['commission_bps'] ?? null) !== null;
            $item['brand_name'] = is_string($brand) ? $brand : '';
            $rows[] = $this->forms->fillNames($item);
        }

        return $rows;
    }

    public function count(string $vendorId): int
    {
        return $this->inventory->count($vendorId);
    }

    public function unsold(string $vendorId): int
    {
        return $this->inventory->unsold($vendorId);
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
