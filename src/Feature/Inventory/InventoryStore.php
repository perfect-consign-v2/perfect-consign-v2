<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class InventoryStore
{
    public function __construct(
        private readonly Statements $statements,
        private readonly CatalogLinks $links,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(string $query): array
    {
        $needle = strtolower(trim($query));
        $sql = 'SELECT item.id, item.code, item.barcode, item.description, item.price_minor, item.quantity,
                       item.vendor_id, item.commission_bps, item.intake_on, item.expires_on,
                       vendor.code AS vendor_code, vendor.name AS vendor_name,
                       COALESCE(item.commission_bps, vendor.commission_bps, mall.commission_bps) AS take_bps,
                       brand.name AS brand_name
                FROM items AS item
                JOIN vendors AS vendor ON vendor.id = item.vendor_id
                CROSS JOIN mall
                LEFT JOIN brands AS brand ON brand.id = item.brand_id';
        if ($needle === '') {
            return $this->statements->rows($sql . ' ORDER BY lower(item.code), item.code');
        }

        return $this->statements->bound(
            $sql . ' WHERE strpos(lower(item.code), ?) > 0
                       OR strpos(lower(item.barcode), ?) > 0
                       OR strpos(lower(item.description), ?) > 0
                       OR strpos(lower(vendor.code), ?) > 0
                       OR strpos(lower(vendor.name), ?) > 0
                       OR strpos(lower(brand.name), ?) > 0
                     ORDER BY lower(item.code), item.code',
            [$needle, $needle, $needle, $needle, $needle, $needle]
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
            'SELECT item.id, item.code, item.barcode, item.description, item.price_minor, item.quantity,
                    item.vendor_id, item.brand_id, item.commission_bps, item.intake_on, item.expires_on,
                    vendor.code AS vendor_code, vendor.name AS vendor_name,
                    COALESCE(vendor.commission_bps, mall.commission_bps) AS vendor_take_bps,
                    brand.name AS brand_name
             FROM items AS item
             JOIN vendors AS vendor ON vendor.id = item.vendor_id
             CROSS JOIN mall
             LEFT JOIN brands AS brand ON brand.id = item.brand_id
             WHERE item.id = ?::uuid',
            [$id]
        );

        return $rows[0] ?? null;
    }

    public function add(PieceEntry $piece, string $today): void
    {
        $this->place(null, $piece, $today);
    }

    public function save(string $id, PieceEntry $piece, string $today): void
    {
        $this->place($id, $piece, $today);
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
        return $this->links->referenced('items');
    }

    private function place(?string $id, PieceEntry $piece, string $today): void
    {
        $this->requireVendor($piece->vendorId);
        $this->requireBrand($piece->brandId);
        $refusal = null;
        $attempt = 0;
        while ($attempt < 5) {
            $attempt++;
            $barcode = $piece->barcode !== '' ? $piece->barcode : $this->allocate($today);
            try {
                $this->statements->isolated(function () use ($id, $piece, $barcode): void {
                    $this->store($id, $piece, $barcode);
                });

                return;
            } catch (DatabaseRefusal $error) {
                $refusal = $error;
                if ($piece->barcode !== '' || !str_contains($error->getMessage(), 'items_barcode_key')) {
                    throw $error;
                }
            }
        }
        throw $refusal;
    }

    private function store(?string $id, PieceEntry $piece, string $barcode): void
    {
        $params = [
            $piece->code,
            $barcode,
            $piece->vendorId,
            $piece->brandId,
            $piece->description,
            $piece->priceMinor,
            $piece->quantity,
            $piece->commissionBps,
            $piece->dates->intakeOn,
            $piece->dates->expiresOn,
        ];
        if ($id === null) {
            $this->statements->change(
                'INSERT INTO items (
                    code, barcode, vendor_id, brand_id, description, price_minor, quantity, commission_bps, intake_on, expires_on
                 ) VALUES (?, ?, ?::uuid, ?::uuid, ?, ?, ?, ?, ?::date, ?::date)',
                $params
            );

            return;
        }
        $params[] = $id;
        $params[] = $piece->priceMinor;
        $params[] = $piece->vendorId;
        $count = $this->statements->affected(
            'UPDATE items
             SET code = ?, barcode = ?, vendor_id = ?::uuid, brand_id = ?::uuid, description = ?,
                 price_minor = ?, quantity = ?, commission_bps = ?, intake_on = ?::date, expires_on = ?::date
             WHERE id = ?::uuid
               AND (
                   NOT EXISTS (SELECT 1 FROM sale_lines AS sale WHERE sale.item_id = items.id)
                   OR (price_minor = ? AND vendor_id = ?::uuid)
               )',
            $params
        );
        if ($count === 0) {
            $this->refuseSoldChange($id, $piece);
        }
    }

    public function sold(string $id): bool
    {
        $rows = $this->statements->bound(
            'SELECT 1 FROM sale_lines WHERE item_id = ?::uuid LIMIT 1',
            [$id]
        );

        return $rows !== [];
    }

    private function refuseSoldChange(string $id, PieceEntry $piece): void
    {
        $row = $this->find($id);
        if ($row === null) {
            throw new \InvalidArgumentException('That piece is not in this mall.');
        }
        if ($piece->priceMinor !== RowValue::integer($row['price_minor'] ?? null)) {
            throw new \InvalidArgumentException('That price is already on a sale.');
        }
        if ($piece->vendorId !== RowValue::string($row['vendor_id'] ?? null)) {
            throw new \InvalidArgumentException('That consignor is already on a sale.');
        }
        throw new \InvalidArgumentException('That piece is not in this mall.');
    }

    private function allocate(string $today): string
    {
        $stamp = str_replace('-', '', $today);
        if (preg_match('/^\d{8}$/', $stamp) !== 1) {
            throw new \InvalidArgumentException('A barcode could not be generated.');
        }
        $prefix = 'BC' . $stamp;
        $rows = $this->statements->bound(
            'SELECT barcode FROM items WHERE barcode ~ ? ORDER BY barcode DESC LIMIT 1',
            ['^' . $prefix . '[0-9]{4}$']
        );
        $last = $rows[0]['barcode'] ?? null;
        $sequence = 1;
        if (is_string($last) && preg_match('/(\d{4})$/', $last, $parts) === 1) {
            $sequence = ((int) $parts[1]) + 1;
        }
        if ($sequence > 9999) {
            throw new \InvalidArgumentException('A barcode could not be generated.');
        }

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function requireVendor(string $vendorId): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1) {
            throw new \InvalidArgumentException('That vendor is not in this mall.');
        }
        $rows = $this->statements->bound(
            'SELECT id FROM vendors WHERE id = ?::uuid',
            [$vendorId]
        );
        if ($rows === []) {
            throw new \InvalidArgumentException('That vendor is not in this mall.');
        }
    }

    private function requireBrand(?string $brandId): void
    {
        if ($brandId === null || $brandId === '') {
            return;
        }
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $brandId) !== 1) {
            throw new \InvalidArgumentException('That brand is not in this mall.');
        }
        $rows = $this->statements->bound(
            'SELECT id FROM brands WHERE id = ?::uuid',
            [$brandId]
        );
        if ($rows === []) {
            throw new \InvalidArgumentException('That brand is not in this mall.');
        }
    }
}
