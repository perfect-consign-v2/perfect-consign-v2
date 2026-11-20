<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

final class RequestTrace
{
    public function __construct(
        public readonly string $accept = '',
        public readonly string $target = '',
        public readonly string $host = '',
        public readonly string $userAgent = '',
        public readonly string $rawReferer = '',
    ) {
    }
}
