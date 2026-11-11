<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

/** Optional face lines on a sticker beyond barcode, price, and description. */
final class StickerMeta
{
    public function __construct(
        public readonly string $brand,
        public readonly string $size,
        public readonly string $intake,
        public readonly string $tag,
        public readonly string $deal = '',
    ) {
    }
}
