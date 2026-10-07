<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class StickerFace
{
    /**
     * @param list<StickerLine> $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly int $bars,
        public readonly int $code,
        private readonly int $thin,
    ) {
    }

    public function isNarrow(): bool
    {
        return $this->thin === 1;
    }
}
