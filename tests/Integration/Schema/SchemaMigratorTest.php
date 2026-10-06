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
        $record->stamp($schema, ['044_schema_migrations.sql']);
        $applied = $record->applied($schema);
        self::assertArrayHasKey('044_schema_migrations.sql', $applied);
        self::assertArrayHasKey('001_kernel.sql', $applied);
        self::assertArrayNotHasKey('000_platform.sql', $applied);
        self::assertArrayHasKey('047_platform_schema_migrations.sql', $record->applied('platform'));
        $pdo->exec("DELETE FROM schema_migrations WHERE filename = '044_schema_migrations.sql'");
        $migrator->migrateTenant(ProbeMall::SLUG);
        $restored = $record->applied($schema);
        self::assertArrayHasKey('044_schema_migrations.sql', $restored);
        $checksum = $restored['001_kernel.sql'];
        $pdo->prepare('UPDATE schema_migrations SET checksum = ? WHERE filename = ?')->execute([str_repeat('ab', 32), '001_kernel.sql']);
        try {
            $migrator->migrateTenant(ProbeMall::SLUG);
            self::fail('a changed migration was accepted');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('001_kernel.sql changed after it was applied', $error->getMessage());
        } finally {
            $pdo->prepare('UPDATE schema_migrations SET checksum = ? WHERE filename = ?')->execute([$checksum, '001_kernel.sql']);
        }
    }

    public function testAPlatformInstallRollsBackAndATenantBaselineStamps(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $catalog = new SchemaCatalog($root . DIRECTORY_SEPARATOR . 'sql');
        $migrator = new SchemaMigrator($pdo, $catalog);
        $record = new MigrationRecord($pdo, $catalog);
        $schema = $this->schema($pdo);
        $migrator->migrateTenant('mall');
        $pdo->beginTransaction();
        try {
            $pdo->exec('DROP EVENT TRIGGER platform_reject_guard_function_replace');
            $pdo->exec('DROP EVENT TRIGGER platform_reject_guard_function_again');
            $pdo->exec('DROP TABLE platform.schema_migrations');
            $migrator->migratePlatform();
            self::assertTrue($record->ready('platform'));
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
        $pdo->exec('DROP TABLE platform.schema_migrations');
        $migrator->migratePlatform();
        self::assertArrayHasKey('047_platform_schema_migrations.sql', $record->applied('platform'));
        $pdo->exec("DELETE FROM platform.schema_migrations WHERE filename = '047_platform_schema_migrations.sql'");
        $migrator->migratePlatform();
        self::assertArrayHasKey('047_platform_schema_migrations.sql', $record->applied('platform'));
        $pdo->exec('DROP TABLE ' . $schema . '.schema_migrations');
        $migrator->migrateTenant(ProbeMall::SLUG);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $applied = $record->applied($schema);
        self::assertArrayHasKey('001_kernel.sql', $applied);
        self::assertArrayHasKey('044_schema_migrations.sql', $applied);
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
