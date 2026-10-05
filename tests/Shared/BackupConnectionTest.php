<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PerfectConsign\Feature\Backup\BackupConnection;
use PHPUnit\Framework\TestCase;

final class BackupConnectionTest extends TestCase
{
    public function testTheLocalBackupConnectionReadsTheEnvironment(): void
    {
        $backup = BackupConnection::stallBackup();
        $admin = BackupConnection::admin();
        self::assertNotSame('', $backup->password);
        self::assertNotSame('', $admin->user);
        self::assertIsBool($backup->isLocalRestoreTarget());
        $pdo = $backup->pdo();
        $one = $pdo->query('SELECT 1');
        self::assertNotFalse($one);
        self::assertSame(1, (int) $one->fetchColumn());
        $root = dirname(__DIR__, 2);
        $tools = (string) file_get_contents($root . '/src/Feature/Backup/PostgresTools.php');
        $posting = (string) file_get_contents($root . '/src/Feature/Rent/RentPosting.php');
        self::assertStringContainsString('dockerEnvFile(', $tools);
        self::assertStringContainsString('removeEnvFile(', $tools);
        self::assertStringContainsString('tenderedMinor(', $posting);
    }
}
