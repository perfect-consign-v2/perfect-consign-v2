<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Schema;

use PDO;
use PerfectConsign\Schema\MigrationRecord;
use PerfectConsign\Schema\SchemaCatalog;
use PerfectConsign\Schema\SchemaMigrator;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchemaMigratorTest extends TestCase
{
    public function testRecordedFilesAreRefusedWhenTheBytesChange(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $catalog = new SchemaCatalog($root . DIRECTORY_SEPARATOR . 'sql');
        $migrator = new SchemaMigrator($pdo, $catalog);
        $record = new MigrationRecord($pdo, $catalog);
        $migrator->migratePlatform();
        $migrator->migrateTenant(ProbeMall::SLUG);
        $schema = $this->schema($pdo);
        $migrator->recordTenant($schema);
        self::assertTrue($record->ready('platform'));
        $record->verify('platform');
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $record->verify($schema);
        $applied = $record->applied($schema);
        self::assertArrayHasKey('001_tenant.sql', $applied);
        self::assertArrayNotHasKey('000_platform.sql', $applied);
        self::assertArrayHasKey('000_platform.sql', $record->applied('platform'));
        $checksum = $applied['001_tenant.sql'];
        $pdo->prepare('UPDATE schema_migrations SET checksum = ? WHERE filename = ?')->execute([str_repeat('ab', 32), '001_tenant.sql']);
        try {
            $migrator->migrateTenant(ProbeMall::SLUG);
            self::fail('a changed migration was accepted');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('001_tenant.sql changed after it was applied', $error->getMessage());
        } finally {
            $pdo->prepare('UPDATE schema_migrations SET checksum = ? WHERE filename = ?')->execute([$checksum, '001_tenant.sql']);
        }
    }

    public function testAnInstalledDatabaseIsNotRebuilt(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $catalog = new SchemaCatalog($root . DIRECTORY_SEPARATOR . 'sql');
        $migrator = new SchemaMigrator($pdo, $catalog);
        $record = new MigrationRecord($pdo, $catalog);
        $schema = $this->schema($pdo);
        $pdo->beginTransaction();
        try {
            $pdo->exec('DROP TABLE platform.schema_migrations');
            $migrator->migratePlatform();
            self::fail('an installed platform was rebuilt');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('older schema', $error->getMessage());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        $guard = $pdo->query(
            "SELECT 1 FROM pg_event_trigger WHERE evtname = 'platform_reject_guard_function_replace'"
        );
        self::assertNotFalse($guard);
        self::assertNotFalse($guard->fetchColumn());
        self::assertArrayHasKey('000_platform.sql', $record->applied('platform'));
        $pdo->beginTransaction();
        try {
            $pdo->exec('DROP TABLE ' . $schema . '.schema_migrations');
            $migrator->migrateTenant(ProbeMall::SLUG);
            self::fail('an installed tenant was rebuilt');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('older schema', $error->getMessage());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        self::assertArrayHasKey('001_tenant.sql', $record->applied($schema));
        try {
            $migrator->migrateTenant('Not Slug');
            self::fail('an invalid slug was accepted');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('short lowercase', $error->getMessage());
        }
        try {
            $migrator->migrateTenant('missing-mall-slug');
            self::fail('a missing tenant was accepted');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('is not provisioned', $error->getMessage());
        }
        try {
            $migrator->recordTenant('public');
            self::fail('a public schema was recorded');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('not a tenant schema', $error->getMessage());
        }
    }

    public function testACurrentTenantIsLeftStamped(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $catalog = new SchemaCatalog($root . DIRECTORY_SEPARATOR . 'sql');
        $migrator = new SchemaMigrator($pdo, $catalog);
        $record = new MigrationRecord($pdo, $catalog);
        $schema = $this->schema($pdo);
        $migrator->migrateTenant(ProbeMall::SLUG);
        self::assertArrayHasKey('001_tenant.sql', $record->applied($schema));
    }

    public function testASecondMigrationIsRefused(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $catalog = new SchemaCatalog($root . DIRECTORY_SEPARATOR . 'sql');
        $record = new MigrationRecord($pdo, $catalog);
        $other = new MigrationRecord($this->connection(), $catalog);
        $record->holdMigration();
        try {
            $other->holdMigration();
            self::fail('a second migration was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('a migration is already running', $error->getMessage());
        } finally {
            $record->releaseMigration();
        }
    }

    private function schema(PDO $pdo): string
    {
        $listed = $pdo->query("SELECT schema_name FROM platform.tenants WHERE slug = 'webprobe'");
        if ($listed === false) {
            self::fail('webprobe schema is missing');
        }
        $schema = $listed->fetchColumn();
        if (!is_string($schema) || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            self::fail('webprobe schema is missing');
        }

        return $schema;
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
