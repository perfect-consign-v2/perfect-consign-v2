<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Database;

/**
 * pdo_pgsql sends PHP false as an empty string, which boolean columns reject.
 * Bind this literal and cast the placeholder with ?::boolean.
 */
final class PgBool
{
    public static function literal(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
