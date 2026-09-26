<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

final readonly class PageResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public string $location,
    ) {
    }
}
