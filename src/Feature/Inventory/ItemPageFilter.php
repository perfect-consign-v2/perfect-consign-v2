<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

/** Stock, vendor, address-bar q, column filters, and the search box. */
final class ItemPageFilter
{
    /**
     * @param array<string, string> $columns
     * @param list<string> $keys
     */
    public function __construct(
        public readonly string $query,
        public readonly string $stock,
        public readonly string $vendorId,
        public readonly array $columns,
        public readonly string $search,
        public readonly array $keys,
    ) {
    }
}
