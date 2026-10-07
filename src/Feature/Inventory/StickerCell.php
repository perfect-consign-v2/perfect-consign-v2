<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class StickerCell
{
    /**
     * @param list<StickerLine> $lines
     */
    public function __construct(
        public readonly string $style,
        public readonly array $lines,
        public readonly string $image,
        public readonly string $barHeight,
        public readonly string $barcode,
        public readonly string $codeHeight,
    ) {
    }
}
