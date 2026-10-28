<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

final class StickerFromRow
{
    /**
     * @param array<string, mixed> $row
     */
    public function item(array $row, string $store): StickerItem
    {
        $brand = $row['brand_name'] ?? null;

        return new StickerItem(
            RowValue::string($row['barcode'] ?? null),
            View::money(RowValue::integer($row['price_minor'] ?? null)),
            RowValue::string($row['description'] ?? null),
            $store,
            RowValue::string($row['vendor_code'] ?? null),
            is_string($brand) ? $brand : '',
            $this->intake(RowValue::string($row['intake_on'] ?? null)),
            RowValue::string($row['code'] ?? null),
            is_string($row['deal_name'] ?? null) ? $row['deal_name'] : '',
        );
    }

    private function intake(string $day): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if ($date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $day) {
            return $date->format('m-d-Y');
        }

        return $day;
    }
}
