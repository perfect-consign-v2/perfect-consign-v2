<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Backup;

use PDO;
use PerfectConsign\Feature\Backup\BackupException;
use PerfectConsign\Feature\Backup\PlatformSnapshot;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;

final class PlatformSnapshotTest extends TestCase
{
    public function testTheSnapshotNamesTheMallAndItsOwner(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->connection();
        $snapshot = new PlatformSnapshot();
        $tenant = $snapshot->tenant($pdo, ProbeMall::SLUG);
        $owner = $snapshot->owner($pdo, $tenant['id']);
        $sql = $snapshot->platformSql($pdo, $tenant, $owner);
        $bare = $snapshot->platformSql($pdo, $tenant, null);
        $manifest = $snapshot->manifest(
            '00000000-0000-7000-8000-0000000000aa',
            '2026-10-07T00:00:00Z',
            $tenant,
            $owner,
            '16.10',
            str_repeat('ab', 32),
        );
        $withoutOwner = $snapshot->manifest(
            '00000000-0000-7000-8000-0000000000aa',
            '2026-10-07T00:00:00Z',
            $tenant,
            null,
            '16.10',
            str_repeat('ab', 32),
        );

        self::assertSame(ProbeMall::SLUG, $tenant['slug']);
        self::assertStringContainsString('INSERT INTO platform.tenants', $sql);
        self::assertStringNotContainsString('platform.owners', $bare);
        self::assertStringContainsString('slug=' . ProbeMall::SLUG, $manifest);
        self::assertStringNotContainsString('owner_id=', $withoutOwner);
        self::assertNull($snapshot->owner($pdo, '00000000-0000-7000-8000-0000000000bb'));
        if ($owner !== null) {
            self::assertStringContainsString('INSERT INTO platform.owners', $sql);
            self::assertStringContainsString('owner_username=' . $owner['username'], $manifest);
        }
        $this->expectException(BackupException::class);
        $snapshot->tenant($pdo, 'missing-mall');
    }

    private function connection(): PDO
    {
        return new PDO(
            'pgsql:host=' . $this->env('STALL_DB_HOST') . ';port=' . $this->env('STALL_DB_PORT') . ';dbname=' . $this->env('STALL_DB_NAME'),
            $this->env('STALL_DB_USER'),
            $this->env('STALL_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            self::fail($name . ' is required');
        }

        return $value;
    }
}
