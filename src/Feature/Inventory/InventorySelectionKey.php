<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

/**
 * sessionStorage key for piece checkbox selection.
 * Posted with sticker preview and accepted only from this allowlist.
 */
final class InventorySelectionKey
{
    private const string PATTERN = '/^pc\.inventorySelection\.v1\.(pieces|vendor)\.[a-z][a-z0-9-]{0,62}$/';

    public static function pieces(string $slug): string
    {
        return 'pc.inventorySelection.v1.pieces.' . $slug;
    }

    public static function vendor(string $slug): string
    {
        return 'pc.inventorySelection.v1.vendor.' . $slug;
    }

    public static function fromRequest(string $raw): ?string
    {
        if (preg_match(self::PATTERN, $raw) === 1) {
            return $raw;
        }

        return null;
    }
}
