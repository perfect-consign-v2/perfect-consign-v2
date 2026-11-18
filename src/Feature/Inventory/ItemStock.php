<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

/** Stock segment SQL for the items list. An unknown segment is the whole floor. */
final class ItemStock
{
    public static function sql(string $stock): string
    {
        return match ($stock) {
            'available' => 'item.quantity > 0',
            'out' => 'item.quantity = 0',
            'sold' => 'item.quantity = 0 AND EXISTS (SELECT 1 FROM sale_lines AS sold WHERE sold.item_id = item.id LIMIT 1)',
            'unprinted' => 'item.sticker_printed_at IS NULL',
            default => '',
        };
    }
}
