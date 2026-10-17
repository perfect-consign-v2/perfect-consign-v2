<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Shared\Scalar\RowValue;

final class ItemListRows
{
    public function __construct(
        private readonly IntakeCatalog $catalog,
        private readonly StoreTake $take,
        private readonly ItemForm $forms,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, true> $held
     * @return list<array<string, mixed>>
     */
    public function mark(array $items, array $held): array
    {
        $marked = [];
        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            $expires = $item['expires_on'] ?? null;
            $brand = $item['brand_name'] ?? null;
            $item['deletable'] = is_string($id) && !isset($held[$id]);
            $item['vendor_label'] = $this->catalog->label(
                RowValue::string($item['vendor_code'] ?? null),
                RowValue::string($item['vendor_name'] ?? null),
            );
            $item['barcode'] = RowValue::string($item['barcode'] ?? null);
            $item['quantity'] = RowValue::integer($item['quantity'] ?? null);
            $item['intake_on'] = RowValue::string($item['intake_on'] ?? null);
            $item['expires_on'] = is_string($expires) ? $expires : '';
            $item['take_label'] = $this->take->format(RowValue::integer($item['take_bps'] ?? null));
            $item['overridden'] = ($item['commission_bps'] ?? null) !== null;
            $item['brand_name'] = is_string($brand) ? $brand : '';
            $marked[] = $this->forms->fillNames($item);
        }

        return $marked;
    }
}
