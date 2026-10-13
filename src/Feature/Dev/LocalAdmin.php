<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Dev;

use PerfectConsign\Feature\Backup\BackupConnection;
use RuntimeException;

final class LocalAdmin
{
    public static function open(): BackupConnection
    {
        self::fill('STALL_DB_HOST', '127.0.0.1');
        self::fill('STALL_DB_PORT', '5436');
        self::fill('STALL_DB_NAME', 'consign');
        self::fill('STALL_DB_USER', 'stall');
        self::fill('STALL_DB_PASSWORD', 'stall');
        $connection = BackupConnection::admin();
        if (!$connection->isLocalRestoreTarget()) {
            throw new RuntimeException('test data reset is only allowed on the local consign database');
        }

        return $connection;
    }

    private static function fill(string $key, string $value): void
    {
        $current = getenv($key);
        if (is_string($current) && $current !== '') {
            return;
        }
        putenv($key . '=' . $value);
    }
}
