<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class LabelInk
{
    public function __construct(
        public readonly int $barMin,
        public readonly int $barCap,
        public readonly int $codeHeight,
        public readonly int $padX,
        public readonly int $padY,
    ) {
    }
}
