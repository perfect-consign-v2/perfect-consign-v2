<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Backup;

use PDO;
use PDOException;
use PDOStatement;
use PerfectConsign\Feature\Backup\BackupStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PHPUnit\Framework\TestCase;

final class BackupStoreTest extends TestCase
{
    public function testALatestBackupWithNoRowIsEmpty(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn(false);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        self::assertNull((new BackupStore($pdo))->latest('01900000-0000-7000-8000-000000000001'));

        $dated = $this->createStub(PDOStatement::class);
        $dated->method('execute')->willReturn(true);
        $dated->method('fetchColumn')->willReturn('2026-10-01 00:00:00+00');
        $withDate = $this->createStub(PDO::class);
        $withDate->method('prepare')->willReturn($dated);
        $latest = (new BackupStore($withDate))->latest('01900000-0000-7000-8000-000000000001');
        self::assertNotNull($latest);
        self::assertSame('2026-10-01', $latest->format('Y-m-d'));

        $empty = $this->createStub(PDOStatement::class);
        $empty->method('execute')->willReturn(true);
        $empty->method('fetchColumn')->willReturn(null);
        $missing = $this->createStub(PDO::class);
        $missing->method('prepare')->willReturn($empty);
        self::assertNull((new BackupStore($missing))->latest('01900000-0000-7000-8000-000000000001'));
    }

    public function testADatabaseRefusalIsRethrown(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willThrowException(new PDOException('SQLSTATE[08006] down'));

        try {
            (new BackupStore($pdo))->latest('01900000-0000-7000-8000-000000000001');
            self::fail('a down database was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('That could not be saved.', $error->getMessage());
        }

        try {
            (new BackupStore($pdo))->record(
                '01900000-0000-7000-8000-000000000001',
                '01900000-0000-7000-8000-000000000002',
                12,
                str_repeat('a', 64)
            );
            self::fail('a down database recorded a backup');
        } catch (DatabaseRefusal $error) {
            self::assertSame('That could not be saved.', $error->getMessage());
        }
    }
}
