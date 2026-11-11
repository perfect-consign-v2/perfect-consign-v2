<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class InventoryStore
{
    private readonly ItemGuard $guard;
    private readonly ItemMarkAllocator $marks;

    public function __construct(
        private readonly Statements $statements,
        private readonly CatalogLinks $links,
        private readonly MallSettings $mall,
    ) {
        $this->guard = new ItemGuard($statements);
        $this->marks = new ItemMarkAllocator($statements);
    }

    /**
     * @param list<string> $ids
     */
    public function markStickersPrinted(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $marks = [];
        $params = [];
        foreach ($ids as $id) {
            $marks[] = '?::uuid';
            $params[] = $id;
        }
        $this->statements->change(
            'UPDATE items SET sticker_printed_at = CURRENT_TIMESTAMP
             WHERE id IN (' . implode(', ', $marks) . ')
               AND sticker_printed_at IS NULL',
            $params
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $rows = $this->statements->bound(
            'SELECT item.id, item.code, item.barcode, item.description, item.price_minor, item.quantity,
                    item.vendor_id, item.brand_id, item.commission_bps, item.intake_on, item.expires_on,
                    item.color_id, item.category_id, item.size_id, item.condition_id, item.department_id,
                    vendor.code AS vendor_code, vendor.name AS vendor_name,
                    COALESCE(vendor.commission_bps, mall.commission_bps) AS vendor_take_bps,
                    brand.name AS brand_name,
                    color.name AS color_name, category.name AS category_name,
                    size.name AS size_name, condition.name AS condition_name,
                    department.name AS department_name,
                    stall_sticker_deal(item.id) AS deal_name
             FROM items AS item
             LEFT JOIN vendors AS vendor ON vendor.id = item.vendor_id
             CROSS JOIN mall
             LEFT JOIN brands AS brand ON brand.id = item.brand_id
             LEFT JOIN colors AS color ON color.id = item.color_id
             LEFT JOIN categories AS category ON category.id = item.category_id
             LEFT JOIN sizes AS size ON size.id = item.size_id
             LEFT JOIN conditions AS condition ON condition.id = item.condition_id
             LEFT JOIN departments AS department ON department.id = item.department_id
             WHERE item.id = ?::uuid',
            [$id]
        );

        return $rows[0] ?? null;
    }

    public function add(ItemEntry $item, string $today): string
    {
        return $this->place(null, $item, $today);
    }

    public function save(string $id, ItemEntry $item, string $today): void
    {
        $this->place($id, $item, $today);
    }

    public function remove(string $id): void
    {
        if (isset($this->referenced()[$id])) {
            throw new \InvalidArgumentException('That item is in use.');
        }
        $count = $this->statements->affected('DELETE FROM items WHERE id = ?::uuid', [$id]);
        if ($count === 0) {
            throw new \InvalidArgumentException('That item is not in this mall.');
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

    /**
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    public function forLabels(array $ids): array
    {
        $rows = [];
        foreach ($ids as $id) {
            $row = $this->find($id);
            if ($row === null) {
                throw new \InvalidArgumentException('That item is not in this mall.');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function place(?string $id, ItemEntry $item, string $today): string
    {
        $this->guard->accept($item);
        $refusal = null;
        $attempt = 0;
        $stored = '';
        $code = $item->code;
        $barcode = $item->barcode;
        while ($attempt < 5) {
            $attempt++;
            $code = $code !== '' ? $code : $this->marks->tag($today);
            $barcode = $barcode !== '' ? $barcode : $this->marks->barcode($today);
            try {
                $this->statements->isolated(function () use ($id, $item, $code, $barcode, &$stored): void {
                    $stored = $this->store($id, $item, $code, $barcode);
                });

                return $stored;
            } catch (DatabaseRefusal $error) {
                $refusal = $error;
                [$code, $barcode, $retry] = $this->retryMarks($item, $error, $code, $barcode);
                if (!$retry) {
                    throw $error;
                }
            }
        }
        throw $refusal;
    }

    /**
     * @return array{0: string, 1: string, 2: bool}
     */
    private function retryMarks(ItemEntry $item, DatabaseRefusal $error, string $code, string $barcode): array
    {
        $retry = false;
        if ($item->code === '' && DatabaseRefusal::isTagUsed($error)) {
            $code = '';
            $retry = true;
        }
        if ($item->barcode === '' && DatabaseRefusal::isBarcodeUsed($error)) {
            $barcode = '';
            $retry = true;
        }

        return [$code, $barcode, $retry];
    }

    private function store(?string $id, ItemEntry $item, string $code, string $barcode): string
    {
        $columns = ['code', 'barcode', 'vendor_id', 'placement'];
        $casts = ['?', '?', '?::uuid', '?'];
        $params = [
            $code,
            $barcode,
            $item->vendorId === '' ? null : $item->vendorId,
            $item->placement,
        ];
        if ($this->mall->enabled('use_brand')) {
            $columns[] = 'brand_id';
            $casts[] = '?::uuid';
            $params[] = $item->brandId;
        }
        $columns = array_merge($columns, ['description', 'price_minor', 'quantity', 'commission_bps', 'intake_on', 'expires_on']);
        $casts = array_merge($casts, ['?', '?', '?', '?', '?::date', '?::date']);
        $params = array_merge($params, [
            $item->description,
            $item->sale->priceMinor,
            $item->sale->quantity,
            $item->sale->commissionBps,
            $item->dates->intakeOn,
            $item->dates->expiresOn,
        ]);
        foreach (NameList::all() as $list) {
            if (!$this->mall->enabled($list->flag)) {
                continue;
            }
            $columns[] = $list->itemColumn;
            $casts[] = '?::uuid';
            $params[] = $item->attributes[$list->key] ?? null;
        }
        if ($id === null) {
            return $this->inserted($columns, $casts, $params);
        }
        $assignments = [];
        foreach ($columns as $index => $column) {
            $assignments[] = $column . ' = ' . $casts[$index];
        }
        $params[] = $id;
        $params[] = $item->sale->priceMinor;
        $params[] = $item->vendorId;
        $count = $this->statements->affected(
            'UPDATE items SET ' . implode(', ', $assignments) . '
             WHERE id = ?::uuid
               AND (
                   NOT EXISTS (SELECT 1 FROM sale_lines AS sale WHERE sale.item_id = items.id)
                   OR (price_minor = ? AND vendor_id = ?::uuid)
               )',
            $params
        );
        if ($count === 0) {
            $this->refuseSoldChange($id, $item);
        }

        return $id;
    }

    /**
     * @param list<string> $columns
     * @param list<string> $casts
     * @param list<mixed> $params
     */
    private function inserted(array $columns, array $casts, array $params): string
    {
        $rows = $this->statements->bound(
            'INSERT INTO items (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $casts) . ') RETURNING id::text AS id',
            $params
        );
        $id = $rows[0]['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException('the item was not saved');
        }

        return $id;
    }

    public function sold(string $id): bool
    {
        $rows = $this->statements->bound(
            'SELECT 1 FROM sale_lines WHERE item_id = ?::uuid LIMIT 1',
            [$id]
        );

        return $rows !== [];
    }

    private function refuseSoldChange(string $id, ItemEntry $item): void
    {
        $row = $this->find($id);
        if ($row === null) {
            throw new \InvalidArgumentException('That item is not in this mall.');
        }
        if ($item->sale->priceMinor !== RowValue::integer($row['price_minor'] ?? null)) {
            throw new \InvalidArgumentException('That price is already on a sale.');
        }
        if ($item->vendorId !== RowValue::string($row['vendor_id'] ?? null)) {
            throw new \InvalidArgumentException(
                $this->mall->getPartyLabel('That vendor is already on a sale.', 'That consignor is already on a sale.')
            );
        }
        throw new \InvalidArgumentException('That item is not in this mall.');
    }

}
