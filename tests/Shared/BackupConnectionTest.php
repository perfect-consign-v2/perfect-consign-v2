<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PerfectConsign\Feature\Backup\BackupConnection;
use PerfectConsign\Feature\Backup\BackupException;
use PHPUnit\Framework\TestCase;

final class BackupConnectionTest extends TestCase
{
    public function testTheLocalBackupConnectionReadsTheEnvironment(): void
    {
        $this->fill('STALL_DB_USER', 'stall');
        $this->fill('STALL_DB_PASSWORD', 'stall');
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
        $local = new BackupConnection('localhost', '5436', 'consign', 'stall', 'stall');
        self::assertTrue($local->isLocalRestoreTarget());
        $loopback = new BackupConnection('127.0.0.1', '5436', 'consign', 'stall', 'stall');
        self::assertTrue($loopback->isLocalRestoreTarget());
        $remote = new BackupConnection('127.0.0.1', '5432', 'consign', 'stall', 'stall');
        self::assertFalse($remote->isLocalRestoreTarget());
        $elsewhere = new BackupConnection('db.example', '5436', 'consign', 'stall', 'stall');
        self::assertFalse($elsewhere->isLocalRestoreTarget());
        $this->refused('bad host', '5436', 'consign', 'stall', 'stall');
        $this->refused('127.0.0.1', 'port', 'consign', 'stall', 'stall');
        $this->refused('127.0.0.1', '5436', '9bad', 'stall', 'stall');
        $this->refused('127.0.0.1', '5436', 'consign', 'bad user', 'stall');
        $this->refused('127.0.0.1', '5436', 'consign', 'stall', '');
        $this->refused('127.0.0.1', '5436', 'consign', 'stall', "stall\n");
        $this->fill('STALL_SOURCE_DB_HOST', (string) getenv('STALL_DB_HOST'));
        $this->fill('STALL_SOURCE_DB_PORT', (string) getenv('STALL_DB_PORT'));
        $this->fill('STALL_SOURCE_DB_NAME', (string) getenv('STALL_DB_NAME'));
        $this->fill('STALL_SOURCE_DB_PASSWORD', (string) getenv('STALL_BACKUP_PASSWORD'));
        self::assertNotSame('', BackupConnection::sourceBackup()->password);
        $this->blank('STALL_DB_USER');
        $this->blank('STALL_DB_HOST');
        $this->blank('STALL_DB_PORT');
        $this->blank('STALL_DB_NAME');
        $this->blank('STALL_BACKUP_PASSWORD');
    }

    private function blank(string $key): void
    {
        $previous = getenv($key);
        putenv($key . '=');
        try {
            if ($key === 'STALL_DB_USER') {
                BackupConnection::admin();
            } else {
                BackupConnection::stallBackup();
            }
            self::fail($key . ' was accepted when blank');
        } catch (BackupException $error) {
            if ($key === 'STALL_DB_USER') {
                self::assertSame('STALL_DB_USER is required', $error->getMessage());
            } else {
                self::assertStringContainsString($key, $error->getMessage());
                self::assertStringContainsString('are required', $error->getMessage());
            }
        } finally {
            if (is_string($previous) && $previous !== '') {
                putenv($key . '=' . $previous);
            }
        }
    }

    private function refused(string $host, string $port, string $database, string $user, string $password): void
    {
        try {
            new BackupConnection($host, $port, $database, $user, $password);
            self::fail('an invalid backup connection was accepted');
        } catch (BackupException) {
            self::assertTrue(true);
        }
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
