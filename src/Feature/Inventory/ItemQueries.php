<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class ItemQueries
{
    private const string LIST = 'SELECT item.id, item.code, item.barcode, item.description, item.price_minor, item.quantity,
                       item.vendor_id, item.placement, item.commission_bps, item.intake_on, item.expires_on,
                       vendor.code AS vendor_code, vendor.name AS vendor_name,
                       COALESCE(item.commission_bps, vendor.commission_bps, mall.commission_bps) AS take_bps,
                       brand.name AS brand_name,
                       color.name AS color_name, category.name AS category_name,
                       size.name AS size_name, condition.name AS condition_name,
                       department.name AS department_name
                FROM items AS item
                LEFT JOIN vendors AS vendor ON vendor.id = item.vendor_id
                CROSS JOIN mall
                LEFT JOIN brands AS brand ON brand.id = item.brand_id
                LEFT JOIN colors AS color ON color.id = item.color_id
                LEFT JOIN categories AS category ON category.id = item.category_id
                LEFT JOIN sizes AS size ON size.id = item.size_id
                LEFT JOIN conditions AS condition ON condition.id = item.condition_id
                LEFT JOIN departments AS department ON department.id = item.department_id';

    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(string $query, string $stock = '', string $vendorId = ''): array
    {
        $where = [];
        $params = [];
        if ($vendorId !== '') {
            $where[] = 'item.vendor_id = ?::uuid';
            $params[] = $vendorId;
        }
        if ($stock === 'available') {
            $where[] = 'item.quantity > 0';
        }
        if ($stock === 'out') {
            $where[] = 'item.quantity = 0';
        }
        if ($stock === 'unprinted') {
            $where[] = 'item.sticker_printed_at IS NULL';
        }
        $needle = strtolower(trim($query));
        if ($needle !== '') {
            $where[] = '(strpos(lower(item.code), ?) > 0
                       OR strpos(lower(item.barcode), ?) > 0
                       OR strpos(lower(item.description), ?) > 0
                       OR strpos(lower(vendor.code), ?) > 0
                       OR strpos(lower(vendor.name), ?) > 0
                       OR strpos(lower(brand.name), ?) > 0
                       OR strpos(lower(color.name), ?) > 0
                       OR strpos(lower(category.name), ?) > 0
                       OR strpos(lower(size.name), ?) > 0
                       OR strpos(lower(condition.name), ?) > 0
                       OR strpos(lower(department.name), ?) > 0)';
            $params = array_merge($params, array_fill(0, 11, $needle));
        }
        $sql = self::LIST . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY lower(item.code), item.code';
        if ($params === []) {
            return $this->statements->rows($sql);
        }

        return $this->statements->bound($sql, $params);
    }

    public function count(string $vendorId = ''): int
    {
        return $this->tally('', $vendorId);
    }

    public function available(string $vendorId = ''): int
    {
        return $this->tally(' WHERE quantity > 0', $vendorId);
    }

    public function unsold(string $vendorId = ''): int
    {
        return $this->tally(' WHERE quantity = 0', $vendorId);
    }

    public function unprinted(string $vendorId = ''): int
    {
        return $this->tally(' WHERE sticker_printed_at IS NULL', $vendorId);
    }

    private function tally(string $where, string $vendorId): int
    {
        if ($vendorId === '') {
            $rows = $this->statements->rows('SELECT count(*)::int AS total FROM items' . $where);
        } else {
            $rows = $this->statements->bound(
                'SELECT count(*)::int AS total FROM items' . ($where === '' ? ' WHERE ' : $where . ' AND ') . 'vendor_id = ?::uuid',
                [$vendorId]
            );
        }

        return RowValue::integer($rows[0]['total'] ?? 0);
    }
}
