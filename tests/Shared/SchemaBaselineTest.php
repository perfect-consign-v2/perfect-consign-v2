<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PDO;
use PDOStatement;
use PerfectConsign\Schema\MigrationLock;
use PerfectConsign\Schema\MigrationRecord;
use PerfectConsign\Schema\SchemaBaseline;
use PerfectConsign\Schema\SchemaCatalog;
use PerfectConsign\Schema\SchemaPresence;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchemaBaselineTest extends TestCase
{
    public function testAnInvalidObjectNameIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('schema object name is invalid');
        (new SchemaBaseline($this->createStub(PDO::class)))->absent('tenant_0123456789abcdef0123456789abcdef', ['Bad Name'], []);
    }

    public function testAFailedRegclassQueryIsAbsent(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('quote')->willReturnCallback(static fn (string $value): string => "'" . $value . "'");
        $pdo->method('query')->willReturn(false);
        $missing = (new SchemaBaseline($pdo))->absent('tenant_0123456789abcdef0123456789abcdef', ['booths'], []);

        self::assertSame('booths', $missing);
    }

    public function testAMigrationLockReleaseFailsWhenUnlockCannotRun(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('query')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the migration lock could not be released');
        (new MigrationLock($pdo))->release();
    }

    public function testABrokenMigrationTableNameIsRefused(): void
    {
        $catalog = new SchemaCatalog(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'sql');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('schema name is not a tenant schema');
        (new MigrationRecord($this->createStub(PDO::class), $catalog))->ready('public');
    }

    public function testAQueryThatCannotListMigrationsIsRefused(): void
    {
        $catalog = new SchemaCatalog(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'sql');
        $pdo = $this->createStub(PDO::class);
        $pdo->method('quote')->willReturnCallback(static fn (string $value): string => "'" . $value . "'");
        $named = $this->createStub(PDOStatement::class);
        $named->method('fetchColumn')->willReturn('platform.schema_migrations');
        $pdo->method('query')->willReturnCallback(static function (string $sql) use ($named): PDOStatement|false {
            if (str_contains($sql, 'to_regclass')) {
                return $named;
            }

            return false;
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not read schema migrations');
        (new MigrationRecord($pdo, $catalog))->applied('platform');
    }

    public function testAPresenceCheckRefusesABadSchemaName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('schema name is not a tenant schema');
        (new SchemaPresence($this->createStub(PDO::class)))->coverage('public', [
            'tables' => [],
            'routines' => [],
            'columns' => [],
            'triggers' => [],
            'constraints' => [],
        ]);
    }

    public function testAPresenceCheckRefusesABadObjectName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('schema object name is invalid');
        (new SchemaPresence($this->createStub(PDO::class)))->coverage('tenant_0123456789abcdef0123456789abcdef', [
            'tables' => ['Bad Name'],
            'routines' => [],
            'columns' => [],
            'triggers' => [],
            'constraints' => [],
        ]);
    }
}
