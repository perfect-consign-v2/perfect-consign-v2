<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * A piece whose vendor has left the store. Staff set it aside and hold it for
 * the vendor; the vendor collects it from the Close out vendor page.
 */
final class DepartedPiece
{
    public function __construct(
        private readonly Statements $statements,
        private readonly JournalAudit $audit,
    ) {
    }

    /**
     * @return array{vendor_id: string, vendor_name: string, held_on: string}|null
     */
    public function of(string $itemId): ?array
    {
        $rows = $this->statements->bound(
            'SELECT vendor.id::text AS vendor_id, vendor.name AS vendor_name,
                    COALESCE((SELECT held.noted_on::text FROM held_pieces AS held WHERE held.item_id = item.id LIMIT 1), \'\') AS held_on
             FROM items AS item
             JOIN vendors AS vendor ON vendor.id = item.vendor_id
             WHERE item.id = ?::uuid
               AND NOT vendor.active',
            [$itemId]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return null;
        }

        return [
            'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
            'vendor_name' => RowValue::string($row['vendor_name'] ?? null),
            'held_on' => RowValue::string($row['held_on'] ?? null),
        ];
    }

    public function hold(string $itemId, string $today, string $who): void
    {
        $this->statements->transaction(function () use ($itemId, $today, $who): void {
            $rows = $this->statements->bound(
                "INSERT INTO floor_findings (item_id, kind, noted_on, noted_by)
                 VALUES (?::uuid, 'held', ?::date, ?)
                 RETURNING id::text AS id",
                [$itemId, $today, $who]
            );
            $this->audit->record('item.hold', 'floor_finding', RowValue::string($rows[0]['id'] ?? null));
        });
    }
}
