<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

final class MallLateFeeWrite
{
    public function __construct(
        public readonly bool $enabled,
        public readonly int $grace,
        public readonly int $first,
        public readonly int $next,
    ) {
    }
}
