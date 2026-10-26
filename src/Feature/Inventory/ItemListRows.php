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
            $item['vendor_id'] = RowValue::optionalString($item['vendor_id'] ?? null) ?? '';
            $item['vendor_label'] = $this->catalog->label(
                RowValue::optionalString($item['vendor_code'] ?? null) ?? '',
                RowValue::optionalString($item['vendor_name'] ?? null) ?? '',
            );
            if ($item['vendor_label'] === '' && ($item['placement'] ?? '') === 'store') {
                $item['vendor_label'] = 'Store stock';
            }
            $item['barcode'] = RowValue::optionalString($item['barcode'] ?? null) ?? '';
            $item['quantity'] = RowValue::integer($item['quantity'] ?? null);
            $item['intake_on'] = RowValue::optionalString($item['intake_on'] ?? null) ?? '';
            $item['expires_on'] = is_string($expires) ? $expires : '';
            $item['take_label'] = $this->take->format(RowValue::integer($item['take_bps'] ?? null));
            $item['overridden'] = ($item['commission_bps'] ?? null) !== null;
            $item['brand_name'] = is_string($brand) ? $brand : '';
            $marked[] = $this->forms->fillNames($item);
        }

        return $marked;
    }
}
