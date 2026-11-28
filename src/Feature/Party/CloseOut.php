<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Feature\Inventory\CountWho;
use PerfectConsign\Feature\Inventory\ItemCount;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * The Close out vendor page: what is still in the store for a vendor who is
 * leaving, and what still has to clear before they can be made inactive.
 * Every count change goes through the database with its reason.
 */
final class CloseOut
{
    public function __construct(
        private readonly Statements $statements,
        private readonly ItemCount $counts,
        private readonly JournalAudit $audit,
    ) {
    }

    /**
     * @return array{id: string, name: string, active: bool}|null
     */
    public function vendor(string $vendorId): ?array
    {
        $rows = $this->statements->bound(
            'SELECT id::text AS id, name, active::text AS active FROM vendors WHERE id = ?::uuid',
            [$vendorId]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => RowValue::string($row['id'] ?? null),
            'name' => RowValue::string($row['name'] ?? null),
            'active' => RowValue::string($row['active'] ?? null) === 'true',
        ];
    }

    /**
     * The vendor's pieces still counted in the store, and how many of each sit on an open sale.
     *
     * @return list<array{id: string, code: string, description: string, price: int, quantity: int, on_sale: int}>
     */
    public function items(string $vendorId): array
    {
        $rows = $this->statements->bound(
            "SELECT item.id::text AS id, item.code, item.description, item.price_minor, item.quantity,
                    COALESCE((
                        SELECT sum(line.quantity)
                        FROM ticket_lines AS line
                        JOIN tickets AS ticket ON ticket.id = line.ticket_id
                        WHERE line.item_id = item.id
                          AND ticket.status IN ('open', 'parked', 'layaway')
                    ), 0) AS on_sale
             FROM items AS item
             WHERE item.vendor_id = ?::uuid
               AND item.quantity > 0
             ORDER BY lower(item.code), item.code",
            [$vendorId]
        );
        $listed = [];
        foreach ($rows as $row) {
            $listed[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'code' => RowValue::string($row['code'] ?? null),
                'description' => RowValue::string($row['description'] ?? null),
                'price' => RowValue::integer($row['price_minor'] ?? null),
                'quantity' => RowValue::integer($row['quantity'] ?? null),
                'on_sale' => RowValue::integer($row['on_sale'] ?? null),
            ];
        }

        return $listed;
    }

    /**
     * Pieces found after the vendor left, held until the vendor collects them.
     *
     * @return list<array{item_id: string, code: string, description: string, noted_on: string, noted_by: string}>
     */
    public function held(string $vendorId): array
    {
        $rows = $this->statements->bound(
            'SELECT item_id::text AS item_id, code, description, noted_on::text AS noted_on, noted_by
             FROM held_pieces
             WHERE vendor_id = ?::uuid
             ORDER BY noted_on, lower(code)',
            [$vendorId]
        );
        $listed = [];
        foreach ($rows as $row) {
            $listed[] = [
                'item_id' => RowValue::string($row['item_id'] ?? null),
                'code' => RowValue::string($row['code'] ?? null),
                'description' => RowValue::string($row['description'] ?? null),
                'noted_on' => RowValue::string($row['noted_on'] ?? null),
                'noted_by' => RowValue::string($row['noted_by'] ?? null),
            ];
        }

        return $listed;
    }

    /**
     * What still blocks Make inactive, in the database's own words, keyed by part.
     *
     * @return array<string, string>
     */
    public function open(string $vendorId): array
    {
        $open = [];
        foreach ($this->statements->bound('SELECT reason, sentence FROM stall_vendor_open_reasons(?::uuid)', [$vendorId]) as $row) {
            $open[RowValue::string($row['reason'] ?? null)] = RowValue::string($row['sentence'] ?? null);
        }

        return $open;
    }

    public function depositsOn(): bool
    {
        $rows = $this->statements->rows('SELECT booth_deposits_enabled::text AS deposits FROM mall');

        return RowValue::string($rows[0]['deposits'] ?? null) === 'true';
    }

    /**
     * When the store may keep what is left, or '' when that choice is off or
     * the vendor still has a booth.
     */
    public function keepFrom(string $vendorId): string
    {
        $rows = $this->statements->bound(
            "SELECT (max(assignment.ends_on) + mall.abandon_wait_days)::text AS keep_from
             FROM booth_assignments AS assignment
             CROSS JOIN mall
             WHERE assignment.vendor_id = ?::uuid
               AND assignment.cancelled_on IS NULL
               AND mall.abandon_wait_days IS NOT NULL
               AND mall.store_stock_enabled
             GROUP BY mall.abandon_wait_days
             HAVING bool_and(assignment.ends_on IS NOT NULL)",
            [$vendorId]
        );

        return RowValue::optionalString($rows[0]['keep_from'] ?? null) ?? '';
    }

    /**
     * Counts one piece out with the chosen reason.
     */
    public function countOut(string $vendorId, string $itemId, string $reasonId, CountWho $who): void
    {
        $this->statements->transaction(function () use ($vendorId, $itemId, $reasonId, $who): void {
            $this->owned($vendorId, $itemId);
            $this->counts->change($itemId, 0, $reasonId, $who);
            $this->audit->record('item.count', 'item', $itemId);
        });
    }

    /**
     * The vendor took everything on their last day: every piece still counted
     * goes to zero as Took it home, together or not at all.
     */
    public function tookEverything(string $vendorId, CountWho $who): int
    {
        $reason = '';
        foreach ($this->counts->reasons($who) as $offered) {
            if ($offered['code'] === 'took_home') {
                $reason = $offered['id'];
            }
        }
        if ($reason === '') {
            throw new DatabaseRefusal('Took it home is switched off. Choose a reason for each piece instead.');
        }
        $done = 0;
        $this->statements->transaction(function () use ($vendorId, $reason, $who, &$done): void {
            foreach ($this->items($vendorId) as $item) {
                $this->counts->change($item['id'], 0, $reason, $who);
                $this->audit->record('item.count', 'item', $item['id']);
                $done++;
            }
        });

        return $done;
    }

    /**
     * D11: the store keeps a piece left behind, once the waiting days have passed.
     */
    public function keep(string $vendorId, string $itemId, string $today): void
    {
        $this->statements->transaction(function () use ($vendorId, $itemId, $today): void {
            $this->owned($vendorId, $itemId);
            $rows = $this->statements->bound(
                "INSERT INTO item_dispositions (item_id, kind, posted_on) VALUES (?::uuid, 'abandoned', ?::date) RETURNING id::text AS id",
                [$itemId, $today]
            );
            if ($rows === []) {
                throw new DatabaseRefusal('That item was not found.');
            }
            $this->audit->record('item.keep', 'item', $itemId);
        });
    }

    /**
     * The vendor collected a piece that was held for them.
     */
    public function pickedUp(string $vendorId, string $itemId, string $today, string $who): void
    {
        $this->statements->transaction(function () use ($vendorId, $itemId, $today, $who): void {
            $rows = $this->statements->bound(
                'INSERT INTO pickups (item_id, vendor_id, picked_up_on, received_by)
                 SELECT held.item_id, held.vendor_id, ?::date, ?
                 FROM held_pieces AS held
                 WHERE held.item_id = ?::uuid AND held.vendor_id = ?::uuid
                 RETURNING id::text AS id',
                [$today, $who, $itemId, $vendorId]
            );
            $id = RowValue::optionalString($rows[0]['id'] ?? null);
            if ($id === null) {
                throw new DatabaseRefusal('That piece is not held for them.');
            }
            $this->audit->record('pickup.record', 'pickup', $id);
        });
    }

    private function owned(string $vendorId, string $itemId): void
    {
        $rows = $this->statements->bound(
            'SELECT 1 FROM items WHERE id = ?::uuid AND vendor_id = ?::uuid',
            [$itemId, $vendorId]
        );
        if ($rows === []) {
            throw new DatabaseRefusal('That item is not theirs.');
        }
    }
}
