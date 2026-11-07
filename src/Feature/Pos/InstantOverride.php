<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

final class InstantOverride
{
    public function __construct(
        public readonly string $user = '',
        public readonly string $password = '',
    ) {
    }
}
