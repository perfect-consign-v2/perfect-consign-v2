<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Backup;

use PerfectConsign\Feature\Backup\BackupConnection;
use PHPUnit\Framework\TestCase;

final class BackupReadTest extends TestCase
{
    public function testTheBackupConnectionSelectsOne(): void
    {
        $this->fill('STALL_DB_USER', 'stall');
        $this->fill('STALL_DB_PASSWORD', 'stall');
        $pdo = BackupConnection::stallBackup()->pdo();
        $one = $pdo->query('SELECT 1');
        self::assertNotFalse($one);
        self::assertSame(1, (int) $one->fetchColumn());
    }

    private function fill(string $key, string $value): void
    {
        $current = getenv($key);
        if (is_string($current) && $current !== '') {
            return;
        }
        putenv($key . '=' . $value);
    }
}
