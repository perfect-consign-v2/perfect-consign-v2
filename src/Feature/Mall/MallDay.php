<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

use DateTimeImmutable;
use DateTimeZone;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class MallDay
{
    public function __construct(private readonly Statements $statements)
    {
    }

    public function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone($this->timeZone())))->format('Y-m-d');
    }

    public function timeZone(): string
    {
        $rows = $this->statements->rows('SELECT time_zone FROM mall');
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new \RuntimeException('the mall has no clock');
        }

        return RowValue::string($row['time_zone'] ?? null);
    }
}
