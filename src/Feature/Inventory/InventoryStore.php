<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class InventoryStore
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(string $query): array
    {
        $needle = strtolower(trim($query));
        $sql = 'SELECT item.id, item.code, item.description, item.price_minor, item.vendor_id,
                       vendor.name AS vendor_name, brand.name AS brand_name
                FROM items AS item
                JOIN vendors AS vendor ON vendor.id = item.vendor_id
                LEFT JOIN brands AS brand ON brand.id = item.brand_id';
        if ($needle === '') {
            return $this->statements->rows($sql . ' ORDER BY lower(item.code), item.code');
        }

        return $this->statements->bound(
            $sql . ' WHERE strpos(lower(item.code), ?) > 0
                       OR strpos(lower(item.description), ?) > 0
                       OR strpos(lower(vendor.name), ?) > 0
                       OR strpos(lower(brand.name), ?) > 0
                     ORDER BY lower(item.code), item.code',
            [$needle, $needle, $needle, $needle]
        );
    }

    public function count(): int
    {
        $rows = $this->statements->rows('SELECT count(*)::int AS total FROM items');

        return RowValue::integer($rows[0]['total'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $rows = $this->statements->bound(
            'SELECT id, code, description, price_minor, vendor_id, brand_id
             FROM items WHERE id = ?::uuid',
            [$id]
        );

        return $rows[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function vendors(): array
    {
        return $this->statements->rows(
            'SELECT id, name FROM vendors ORDER BY lower(name), name'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function brands(): array
    {
        return $this->statements->rows(
            'SELECT id, name FROM brands ORDER BY lower(name), name'
        );
    }

    public function add(string $code, string $vendorId, ?string $brandId, string $description, int $price): void
    {
        $this->requireVendor($vendorId);
        $this->requireBrand($brandId);
        $this->statements->change(
            'INSERT INTO items (code, vendor_id, brand_id, description, price_minor) VALUES (?, ?::uuid, ?::uuid, ?, ?)',
            [$code, $vendorId, $brandId, $description, $price]
        );
    }

    public function save(
        string $id,
        string $code,
        string $vendorId,
        ?string $brandId,
        string $description,
        int $price,
    ): void {
        $this->requireVendor($vendorId);
        $this->requireBrand($brandId);
        $count = $this->statements->affected(
            'UPDATE items
             SET code = ?, vendor_id = ?::uuid, brand_id = ?::uuid, description = ?, price_minor = ?
             WHERE id = ?::uuid',
            [$code, $vendorId, $brandId, $description, $price, $id]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('That piece is not in this mall.');
        }
    }

    public function remove(string $id): void
    {
        if (isset($this->referenced()[$id])) {
            throw new \InvalidArgumentException('That piece is in use.');
        }
        $count = $this->statements->affected('DELETE FROM items WHERE id = ?::uuid', [$id]);
        if ($count === 0) {
            throw new \InvalidArgumentException('That piece is not in this mall.');
        }
    }

    /**
     * Item ids that another row still points at.
     *
     * @return array<string, true>
     */
    public function referenced(): array
    {
        $links = $this->statements->rows(
            "SELECT quote_ident(namespace.nspname) || '.' || quote_ident(child.relname) AS relation,
                    quote_ident(attribute.attname) AS column_name
             FROM pg_constraint AS link
             JOIN pg_class AS child ON child.oid = link.conrelid
             JOIN pg_namespace AS namespace ON namespace.oid = child.relnamespace
             JOIN pg_attribute AS attribute
               ON attribute.attrelid = link.conrelid
              AND attribute.attnum = link.conkey[1]
              AND attribute.attisdropped = false
             WHERE link.confrelid = 'items'::regclass
               AND link.contype = 'f'
               AND cardinality(link.conkey) = 1"
        );
        $held = [];
        foreach ($links as $link) {
            $relation = $link['relation'] ?? null;
            $column = $link['column_name'] ?? null;
            if (!is_string($relation) || !is_string($column) || !$this->quoted($relation, $column)) {
                continue;
            }
            $rows = $this->statements->rows(
                'SELECT DISTINCT ' . $column . '::text AS id FROM ' . $relation . ' WHERE ' . $column . ' IS NOT NULL'
            );
            foreach ($rows as $row) {
                $id = $row['id'] ?? null;
                if (is_string($id) && $id !== '') {
                    $held[$id] = true;
                }
            }
        }

        return $held;
    }

    private function requireVendor(string $vendorId): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1 || $this->missing('vendors', $vendorId)) {
            throw new \InvalidArgumentException('That vendor is not in this mall.');
        }
    }

    private function requireBrand(?string $brandId): void
    {
        if ($brandId === null || $brandId === '') {
            return;
        }
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $brandId) !== 1 || $this->missing('brands', $brandId)) {
            throw new \InvalidArgumentException('That brand is not in this mall.');
        }
    }

    private function missing(string $table, string $id): bool
    {
        if ($table !== 'vendors' && $table !== 'brands') {
            return true;
        }
        $rows = $this->statements->bound(
            'SELECT id FROM ' . $table . ' WHERE id = ?::uuid',
            [$id]
        );

        return $rows === [];
    }

    private function quoted(string $relation, string $column): bool
    {
        return preg_match('/^"[a-z_][a-z0-9_]*"\."[a-z_][a-z0-9_]*"$/', $relation) === 1
            && preg_match('/^"[a-z_][a-z0-9_]*"$/', $column) === 1;
    }
}
