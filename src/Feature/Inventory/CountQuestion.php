<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Shared\Scalar\RowValue;

/**
 * A lower count asks "Why are there fewer?" before anything is saved, with one
 * button per reason. The database refuses a lower count without one anyway.
 */
final class CountQuestion
{
    /**
     * @param array<string, mixed>|null $stored
     */
    public static function needed(?array $stored, string $postedCount, string $reasonId): bool
    {
        if ($stored === null || $reasonId !== '' || preg_match('/^(?:0|[1-9][0-9]{0,8})$/', $postedCount) !== 1) {
            return false;
        }

        return (int) $postedCount < RowValue::integer($stored['quantity'] ?? null);
    }

    /**
     * @param array<string, mixed>|null $stored
     */
    public static function was(?array $stored): string
    {
        return $stored === null ? '' : (string) RowValue::integer($stored['quantity'] ?? null);
    }
}
