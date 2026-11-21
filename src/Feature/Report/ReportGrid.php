<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

final class ReportGrid
{
    /**
     * @param list<ReportColumn> $columns
     * @param list<array<string, int|string|null>> $rows
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
    ) {
    }
}
