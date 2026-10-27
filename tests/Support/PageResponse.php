<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

final readonly class PageResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public string $body,
        public string $location,
        public array $headers = [],
    ) {
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}
