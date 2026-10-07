<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class LabelBox
{
    public function __construct(
        public readonly int $columns,
        public readonly int $rows,
        public readonly int $pageWidth,
        public readonly int $pageHeight,
        public readonly int $marginTop,
        public readonly int $marginLeft,
        public readonly int $width,
        public readonly int $height,
        public readonly int $columnGap,
    ) {
    }
}
