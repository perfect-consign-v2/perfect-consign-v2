<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

/** The address-bar q filter: one needle across the same fields the full list used. */
final class ItemSearch
{
    /**
     * @return array{0: string, 1: list<string>}
     */
    public static function clause(string $needle): array
    {
        if ($needle === '') {
            return ['', []];
        }

        return [
            '(strpos(lower(item.code), ?) > 0
                       OR strpos(lower(item.barcode), ?) > 0
                       OR strpos(lower(item.description), ?) > 0
                       OR strpos(lower(vendor.code), ?) > 0
                       OR strpos(lower(vendor.name), ?) > 0
                       OR strpos(lower(brand.name), ?) > 0
                       OR strpos(lower(color.name), ?) > 0
                       OR strpos(lower(category.name), ?) > 0
                       OR strpos(lower(size.name), ?) > 0
                       OR strpos(lower(condition.name), ?) > 0
                       OR strpos(lower(department.name), ?) > 0)',
            array_fill(0, 11, $needle),
        ];
    }
}
