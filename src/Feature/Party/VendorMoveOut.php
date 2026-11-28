<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Feature\Inventory\ItemCount;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * Vendors whose last booth ends within 14 days, with what is still in the
 * system, and a vendor's item count history.
 */
final class VendorMoveOut
{
    private const NOTICE_DAYS = 14;

    /** Active vendors whose open stays all end, the last of them within the notice days. */
    private const LEAVING = "SELECT vendor.id::text AS vendor_id, vendor.name, last_stay.ends_on::text AS ends_on,
                   COALESCE((SELECT sum(item.quantity) FROM items AS item WHERE item.vendor_id = vendor.id), 0) AS items
            FROM vendors AS vendor
            CROSS JOIN LATERAL (
                SELECT max(assignment.ends_on) AS ends_on, bool_and(assignment.ends_on IS NOT NULL) AS all_end
                FROM booth_assignments AS assignment
                WHERE assignment.vendor_id = vendor.id
                  AND assignment.cancelled_on IS NULL
                  AND (assignment.ends_on IS NULL OR assignment.ends_on >= ?::date)
            ) AS last_stay
            WHERE vendor.active
              AND last_stay.all_end
              AND last_stay.ends_on <= ?::date + ?::integer
              AND (?::uuid IS NULL OR vendor.id = ?::uuid)
            ORDER BY last_stay.ends_on, vendor.name";

    private readonly ItemCount $counts;

    public function __construct(private readonly Statements $statements)
    {
        $this->counts = new ItemCount($statements);
    }

    /**
     * @return list<array{vendor_id: string, name: string, ends_on: string, items: int}>
     */
    public function soon(string $today): array
    {
        return $this->listed($this->statements->bound(self::LEAVING, [$today, $today, self::NOTICE_DAYS, null, null]));
    }

    /**
     * @return array{vendor_id: string, name: string, ends_on: string, items: int}|null
     */
    public function leaving(string $vendorId, string $today): ?array
    {
        $rows = $this->listed($this->statements->bound(self::LEAVING, [$today, $today, self::NOTICE_DAYS, $vendorId, $vendorId]));

        return $rows[0] ?? null;
    }

    /**
     * @return list<array{code: string, said: string}>
     */
    public function history(string $vendorId): array
    {
        return $this->counts->vendorHistory($vendorId);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{vendor_id: string, name: string, ends_on: string, items: int}>
     */
    private function listed(array $rows): array
    {
        $listed = [];
        foreach ($rows as $row) {
            $listed[] = [
                'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
                'name' => RowValue::string($row['name'] ?? null),
                'ends_on' => RowValue::string($row['ends_on'] ?? null),
                'items' => RowValue::integer($row['items'] ?? null),
            ];
        }

        return $listed;
    }
}
