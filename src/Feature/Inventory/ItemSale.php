<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class ItemSale
{
    public function __construct(
        public readonly int $priceMinor,
        public readonly int $quantity,
        public readonly ?int $commissionBps,
    ) {
    }
}
