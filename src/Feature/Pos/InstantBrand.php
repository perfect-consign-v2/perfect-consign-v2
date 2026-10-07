<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

final class InstantBrand
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
    ) {
    }
}
