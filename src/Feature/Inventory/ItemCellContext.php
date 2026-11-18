<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

/** Values the item cells need that are not on the item row. */
final class ItemCellContext
{
    public function __construct(
        public readonly string $slug,
        public readonly string $csrf,
        public readonly string $stock,
        public readonly string $query,
        public readonly string $confirm,
        public readonly string $sellerPath,
        public readonly string $preview,
    ) {
    }
}
