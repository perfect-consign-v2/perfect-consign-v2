<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class IntakeCatalog
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchVendors(string $query): array
    {
        $needle = strtolower(trim($query));
        if ($needle === '') {
            return [];
        }

        return $this->statements->bound(
            'SELECT vendor.id::text AS id, vendor.code, vendor.name,
                    COALESCE(vendor.commission_bps, mall.commission_bps) AS take_bps
             FROM vendors AS vendor
             CROSS JOIN mall
             WHERE strpos(lower(vendor.code), ?) > 0
                OR strpos(lower(vendor.name), ?) > 0
             ORDER BY lower(vendor.code), vendor.code
             LIMIT 20',
            [$needle, $needle]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function vendor(string $id): ?array
    {
        $rows = $this->statements->bound(
            'SELECT vendor.id::text AS id, vendor.code, vendor.name,
                    COALESCE(vendor.commission_bps, mall.commission_bps) AS take_bps
             FROM vendors AS vendor
             CROSS JOIN mall
             WHERE vendor.id = ?::uuid',
            [$id]
        );

        return $rows[0] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function vendorByCode(string $code): ?array
    {
        $needle = strtolower(trim($code));
        if ($needle === '') {
            return null;
        }
        $rows = $this->statements->bound(
            'SELECT vendor.id::text AS id, vendor.code, vendor.name,
                    COALESCE(vendor.commission_bps, mall.commission_bps) AS take_bps
             FROM vendors AS vendor
             CROSS JOIN mall
             WHERE lower(vendor.code) = ?',
            [$needle]
        );

        return $rows[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchBrands(string $query): array
    {
        $needle = strtolower(trim($query));
        if ($needle === '') {
            return [];
        }

        return $this->statements->bound(
            'SELECT id::text AS id, name
             FROM brands
             WHERE strpos(lower(name), ?) > 0
             ORDER BY lower(name), name
             LIMIT 20',
            [$needle]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function brand(string $id): ?array
    {
        $rows = $this->statements->bound(
            'SELECT id::text AS id, name FROM brands WHERE id = ?::uuid',
            [$id]
        );

        return $rows[0] ?? null;
    }

    public function brandId(string $name): ?string
    {
        $rows = $this->statements->bound(
            'SELECT id::text AS id FROM brands WHERE lower(name) = lower(?)',
            [$name]
        );
        $id = $rows[0]['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }

        return $id;
    }

    public function addBrand(string $name): string
    {
        if ($name === '' || $name !== trim($name)) {
            throw new \InvalidArgumentException('Enter a brand name.');
        }
        $rows = $this->statements->bound(
            'INSERT INTO brands (name) VALUES (?) RETURNING id::text AS id',
            [$name]
        );

        return RowValue::string($rows[0]['id'] ?? null);
    }

    public function label(string $code, string $name): string
    {
        return '#' . $code . ' ' . "\u{2014}" . ' ' . $name;
    }
}
