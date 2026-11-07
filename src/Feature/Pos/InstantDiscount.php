<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

final class InstantDiscount
{
    public function __construct(
        public readonly string $kind,
        public readonly string $value,
    ) {
    }
}
