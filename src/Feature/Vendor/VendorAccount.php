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
        return (new VendorFigures($this->statements))->numbers($vendorId);
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
}
