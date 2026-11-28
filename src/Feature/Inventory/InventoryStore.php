<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Bootstrap\JournalAudit;
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
        private readonly JournalAudit $audit,
    ) {
        $this->guard = new ItemGuard($statements, $mall);
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
                    item.vendor_id, item.placement, item.brand_id, item.commission_bps, item.tax_exempt, item.intake_on, item.expires_on,
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
        return $this->kept(null, $item, $today, 'item.create');
    }

    /**
     * Saves the item. The new count is set inside the same transaction by
     * $recount (ItemRecount::apply), through the database with its reason.
     *
     * @param callable(string, int): void $recount
     */
    public function save(string $id, ItemEntry $item, string $today, callable $recount): void
    {
        $this->kept($id, $item, $today, 'item.edit', function (string $stored) use ($item, $recount): void {
            $recount($stored, $item->sale->quantity);
        });
    }

    public function remove(string $id): void
    {
        if (isset($this->referenced()[$id])) {
            throw new \InvalidArgumentException($this->inUse($id));
        }
        $this->statements->transaction(function () use ($id): void {
            $count = $this->statements->affected('DELETE FROM items WHERE id = ?::uuid', [$id]);
            if ($count === 0) {
                throw new \InvalidArgumentException('That item is not in this mall.');
            }
            $this->audit->record('item.delete', 'item', $id);
        });
    }

    private function kept(?string $id, ItemEntry $item, string $today, string $action, ?callable $then = null): string
    {
        $stored = '';
        $this->statements->transaction(function () use ($id, $item, $today, $action, $then, &$stored): void {
            $stored = $this->place($id, $item, $today);
            if ($then !== null) {
                $then($stored);
            }
            $this->audit->record($action, 'item', $stored);
        });

        return $stored;
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
     * Which of these item ids another row still points at.
     *
     * @param list<string> $ids
     * @return array<string, true>
     */
    public function held(array $ids): array
    {
        return $this->links->referencedAmong('items', $ids);
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
        $this->guard->accept($item, $id === null ? null : $this->find($id));
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
        [$columns, $casts, $params] = ItemWriteColumns::fields($this->mall, $item, $code, $barcode);
        if ($id === null) {
            // A new item starts with its count. Every later change goes
            // through stall_change_item_quantity, with its reason.
            return $this->inserted([...$columns, 'quantity'], [...$casts, '?'], [...$params, $item->sale->quantity]);
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

    /**
     * Why an item cannot be deleted. A count changed by hand is history, so the
     * item stays, at a count of 0 when it is gone.
     */
    private function inUse(string $id): string
    {
        $rows = $this->statements->bound(
            "SELECT (EXISTS (SELECT 1 FROM item_quantity_changes WHERE item_id = ?::uuid AND ending IN ('by_hand', 'added', 'found'))
                    AND NOT EXISTS (SELECT 1 FROM sale_lines WHERE item_id = ?::uuid))::text AS counted",
            [$id, $id]
        );

        return RowValue::string($rows[0]['counted'] ?? null) === 'true'
            ? 'This item has a count history, so it stays. Set its count to 0 instead.'
            : 'That item is in use.';
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
