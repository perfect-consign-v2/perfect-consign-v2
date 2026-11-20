<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectConsign\Shared\Database\Statements;

final class SaleCeilingFlag
{
    public function __construct(private readonly Statements $statements)
    {
    }

    public function required(): bool
    {
        $rows = $this->statements->rows('SELECT require_second_signature FROM mall');
        $value = $rows[0]['require_second_signature'] ?? null;

        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
