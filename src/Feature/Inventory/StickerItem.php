<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class StickerItem
{
    public function __construct(
        public readonly string $barcode,
        public readonly string $price,
        public readonly string $description,
        public readonly string $store,
        public readonly string $consignor,
        public readonly string $brand,
        public readonly string $intake,
        public readonly string $tag,
    ) {
    }
}
