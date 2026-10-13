<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

final class MallWrite
{
    public function __construct(
        public readonly int $boothWidth,
        public readonly int $boothDepth,
        public readonly int $floorWidth,
        public readonly int $floorDepth,
        public readonly bool $color,
        public readonly bool $category,
        public readonly bool $size,
        public readonly bool $condition,
        public readonly bool $department,
        public readonly bool $brand,
    ) {
    }
}
