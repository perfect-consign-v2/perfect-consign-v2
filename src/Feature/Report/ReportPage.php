<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

final class ReportPage
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $template,
        public readonly array $data,
        public readonly ?string $csv,
        public readonly string $filename,
    ) {
    }
}
