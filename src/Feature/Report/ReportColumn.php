<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

final class ReportColumn
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $kind,
    ) {
    }
}
