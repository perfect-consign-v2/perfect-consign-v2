<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class BoothVendor
{
    public function __construct(private readonly Statements $statements)
    {
    }

    public function vendor(string $code, string $today): string
    {
        $code = trim($code);
        if ($code === '') {
            throw new \InvalidArgumentException('Choose a booth.');
        }
        $rows = $this->statements->bound(
            'SELECT stay.vendor_id
             FROM booths AS booth
             JOIN booth_assignments AS stay ON stay.booth_id = booth.id
             WHERE lower(booth.code) = lower(?)
               AND stay.starts_on <= ?::date
               AND (stay.ends_on IS NULL OR stay.ends_on >= ?::date)
             ORDER BY stay.starts_on DESC
             LIMIT 1',
            [$code, $today, $today]
        );
        $id = $rows[0]['vendor_id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \InvalidArgumentException('That booth is empty.');
        }

        return RowValue::string($id);
    }
}
