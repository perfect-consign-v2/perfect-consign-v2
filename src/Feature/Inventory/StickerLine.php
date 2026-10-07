<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class StickerLine
{
    public function __construct(
        public readonly string $kind,
        public readonly string $text,
        public readonly string $size,
    ) {
    }
}
