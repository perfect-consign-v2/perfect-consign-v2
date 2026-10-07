<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class StickerSheet
{
    /**
     * @param list<list<StickerCell>> $pages
     */
    public function __construct(
        public readonly string $pageWidth,
        public readonly string $pageHeight,
        private readonly int $thin,
        public readonly array $pages,
    ) {
    }

    public function isNarrow(): bool
    {
        return $this->thin === 1;
    }
}
