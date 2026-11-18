<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

/** One page of items plus the two DataTables counts. */
final class ItemPage
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $total,
        public readonly int $filtered,
        public readonly int $start,
    ) {
    }
}
