<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Feature\Catalog\NameList;

/**
 * Header columns for the staff and vendor item tables. The key is what the browser sends back.
 *
 * @phpstan-type Column array{label: string, actions?: bool, sort?: string, token?: string, select?: bool, key?: string, class?: string}
 */
final class ItemColumns
{
    /**
     * @param list<NameList> $attributes
     * @return list<Column>
     */
    public static function staff(string $brand, array $attributes, string $seller): array
    {
        $columns = [
            ['label' => 'Print', 'actions' => true, 'select' => true],
            ['label' => 'Tag', 'key' => 'code'],
            ['label' => 'Barcode', 'key' => 'barcode'],
            ['label' => 'Description', 'key' => 'description'],
            ['label' => $seller, 'key' => 'vendor'],
        ];

        return [...$columns, ...self::shared($brand, $attributes), ...self::staffTail()];
    }

    /**
     * @param list<NameList> $attributes
     * @return list<Column>
     */
    public static function vendor(string $brand, array $attributes): array
    {
        $columns = [
            ['label' => 'Print', 'actions' => true, 'select' => true],
            ['label' => 'Tag', 'key' => 'code', 'class' => 'strong'],
            ['label' => 'Barcode', 'key' => 'barcode'],
            ['label' => 'Description', 'key' => 'description'],
        ];

        return [...$columns, ...self::shared($brand, $attributes), ...self::vendorTail()];
    }

    /**
     * @param list<Column> $columns
     * @return list<string>
     */
    public static function keys(array $columns): array
    {
        $keys = [];
        foreach ($columns as $column) {
            $key = $column['key'] ?? null;
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param list<NameList> $attributes
     * @return list<Column>
     */
    private static function shared(string $brand, array $attributes): array
    {
        $columns = [];
        if ($brand === 'show') {
            $columns[] = ['label' => 'Brand', 'key' => 'brand'];
        }
        foreach ($attributes as $attribute) {
            $columns[] = ['label' => ucfirst($attribute->singular), 'key' => $attribute->key];
        }
        $columns[] = ['label' => 'Intake', 'key' => 'intake_on'];

        return $columns;
    }

    /**
     * @return list<Column>
     */
    private static function staffTail(): array
    {
        return [
            ['label' => 'Expires', 'key' => 'expires_on'],
            ['label' => 'Status', 'key' => 'status'],
            ['label' => 'Store %', 'key' => 'take', 'sort' => 'num', 'class' => 'num'],
            ['label' => 'Qty', 'key' => 'quantity', 'sort' => 'num', 'class' => 'num'],
            ['label' => 'Price', 'key' => 'price', 'sort' => 'num', 'class' => 'num'],
            ['label' => '', 'actions' => true],
        ];
    }

    /**
     * @return list<Column>
     */
    private static function vendorTail(): array
    {
        return [
            ['label' => 'Days on floor', 'key' => 'days', 'sort' => 'num', 'class' => 'num vendor-days'],
            ['label' => 'Expires', 'key' => 'expires_on'],
            ['label' => 'Store %', 'key' => 'take', 'sort' => 'num', 'class' => 'num'],
            ['label' => 'Qty', 'key' => 'quantity', 'sort' => 'num', 'class' => 'num'],
            ['label' => 'Price', 'key' => 'price', 'sort' => 'num', 'class' => 'num'],
            ['label' => 'Edit', 'actions' => true],
        ];
    }
}
