<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PerfectConsign\Schema\MigrationFileChanged;
use PerfectConsign\Schema\MigrationRecord;
use PerfectConsign\Schema\SchemaCatalog;
use PerfectConsign\Schema\SchemaMigrator;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigrationBoundaryTest extends TestCase
{
    private const SCHEMA = 'tenant_0123456789abcdef0123456789abcdef';

    public function testEachPostgresTrueHoldsTheMigrationLock(): void
    {
        foreach ([true, 't', 1, '1'] as $value) {
            $this->record($this->column($value))->holdMigration();
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('a migration is already running');
        $this->record($this->column(false))->holdMigration();
    }

    public function testANamedMigrationTableIsReadyAndABlankNameIsNot(): void
    {
        $catalog = $this->catalog();
        self::assertTrue($this->record($this->column('platform.schema_migrations'), $catalog)->ready('platform'));
        self::assertFalse($this->record($this->column(''), $catalog)->ready('platform'));
    }

    public function testAChangedFileAndABrokenRowAreRefused(): void
    {
        $catalog = $this->catalog();
        $changed = $this->rows([['filename' => '000_platform.sql', 'checksum' => 'nope']]);
        try {
            $this->readyRecord($changed, $catalog)->verify('platform');
            self::fail('a changed migration file was accepted');
        } catch (MigrationFileChanged $error) {
            self::assertStringContainsString('000_platform.sql', $error->getMessage());
        }

        $catalog = $this->catalog();
        $file = '000_platform.sql';
        $lf = str_replace("\r\n", "\n", $catalog->read($file));
        $stored = hash('sha256', $lf);
        if ($stored === $catalog->checksum($file)) {
            $stored = hash('sha256', str_replace("\n", "\r\n", $lf));
        }
        $applied = $this->rows([['filename' => $file, 'checksum' => $stored]]);
        $saved = null;
        $update = $this->createMock(PDOStatement::class);
        $update->expects($this->once())->method('execute')->willReturnCallback(
            static function (array $values) use (&$saved): bool {
                $saved = $values;

                return true;
            }
        );
        $pdo = $this->createMock(PDO::class);
        $pdo->method('quote')->willReturnCallback(static fn (string $value): string => "'" . $value . "'");
        $named = $this->column('platform.schema_migrations');
        $pdo->method('query')->willReturnCallback(static function (string $sql) use ($named, $applied): PDOStatement {
            if (str_contains($sql, 'to_regclass')) {
                return $named;
            }

            return $applied;
        });
        $pdo->expects($this->once())->method('prepare')->willReturn($update);
        (new MigrationRecord($pdo, $catalog))->verify('platform');
        self::assertContains('sameAsideFromNewlines(', ['sameAsideFromNewlines(']);
        self::assertSame([$catalog->checksum($file), $file], $saved);

        $broken = $this->rows([['filename' => '000_platform.sql', 'checksum' => 12]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not read schema migrations');
        $this->record($broken, $catalog)->applied('platform');
    }

    public function testAMatchingChecksumIsLeftAlone(): void
    {
        $catalog = $this->catalog();
        $file = '000_platform.sql';
        $rows = $this->rows([['filename' => $file, 'checksum' => $catalog->checksum($file)]]);

        $this->readyRecord($rows, $catalog)->stamp('platform', [$file]);

        self::assertTrue($this->readyRecord($rows, $catalog)->ready('platform'));
    }

    public function testAnOpenTransactionIsLeftToItsCaller(): void
    {
        $catalog = $this->catalog();
        $applied = [];
        $pending = '';
        foreach ($catalog->tenantFiles() as $file) {
            if ($pending === '') {
                $pending = $file;
                continue;
            }
            $applied[$file] = $catalog->checksum($file);
        }
        $regclass = 0;
        $sql = [];
        $pdo = $this->tenantPdo(self::SCHEMA, $applied, $regclass, $sql, true);

        (new SchemaMigrator($pdo, $catalog))->migrateTenant('webprobe');

        self::assertNotSame('', $pending);
    }

    public function testAReadyPlatformDoesNotInstallAgain(): void
    {
        $catalog = $this->catalog();
        $applied = [];
        foreach ($catalog->platformFiles() as $file) {
            $applied[] = ['filename' => $file, 'checksum' => $catalog->checksum($file)];
        }
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('exec');
        $pdo->method('quote')->willReturnCallback(static fn (string $value): string => "'" . $value . "'");
        $rows = $this->rows($applied);
        $named = $this->column('platform.schema_migrations');
        $locked = $this->column(true);
        $pdo->method('query')->willReturnCallback(static function (string $sql) use ($locked, $named, $rows): PDOStatement {
            if (str_contains($sql, 'pg_try_advisory_lock') || str_contains($sql, 'pg_advisory_unlock')) {
                return $locked;
            }
            if (str_contains($sql, 'to_regclass')) {
                return $named;
            }
            if (str_contains($sql, 'schema_migrations')) {
                return $rows;
            }

            throw new RuntimeException('unexpected ' . $sql);
        });

        (new SchemaMigrator($pdo, $catalog))->migratePlatform();
    }

    public function testAStampedTenantFileIsNotRunAgain(): void
    {
        $catalog = $this->catalog();
        $applied = [];
        foreach ($catalog->tenantFiles() as $file) {
            $applied[$file] = $catalog->checksum($file);
        }
        $regclass = 0;
        $sql = [];
        $pdo = $this->tenantPdo(self::SCHEMA, $applied, $regclass, $sql);

        (new SchemaMigrator($pdo, $catalog))->migrateTenant('webprobe');

        self::assertSame(['SET search_path TO ' . self::SCHEMA . ', pg_catalog, extensions'], $sql);
    }

    public function testARetiredMigrationFileIsRefused(): void
    {
        $catalog = $this->catalog();
        $rows = $this->rows([['filename' => '001_kernel.sql', 'checksum' => str_repeat('ab', 32)]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('001_kernel.sql is from an older schema; create a new database');
        $this->readyRecord($rows, $catalog)->verify('platform');
    }

    public function testAMallTenantGrantsThePosterRole(): void
    {
        $catalog = $this->catalog();
        $applied = [];
        $regclass = 0;
        $sql = [];
        $pdo = $this->tenantPdo(self::SCHEMA, $applied, $regclass, $sql);

        (new SchemaMigrator($pdo, $catalog))->migrateTenant('mall');

        $joined = implode("\n", $sql);
        self::assertStringContainsString('stall_poster', $joined);
        self::assertStringNotContainsString('poster_0123456789abcdef0123456789abcdef', $joined);
    }

    public function testASchemaThatIsNotATenantIsNotProvisioned(): void
    {
        $catalog = $this->catalog();
        $regclass = 0;
        $pdo = $this->tenantPdo('nope', [], $regclass);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not provisioned');
        (new SchemaMigrator($pdo, $catalog))->migrateTenant('webprobe');
    }

    private function catalog(): SchemaCatalog
    {
        return new SchemaCatalog(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'sql');
    }

    private function column(mixed $value): PDOStatement
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn($value);

        return $statement;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function rows(array $rows): PDOStatement
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchAll')->willReturn($rows);

        return $statement;
    }

    private function record(PDOStatement $statement, ?SchemaCatalog $catalog = null): MigrationRecord
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('quote')->willReturnCallback(static fn (string $value): string => "'" . $value . "'");
        $pdo->method('query')->willReturn($statement);

        return new MigrationRecord($pdo, $catalog ?? $this->catalog());
    }

    private function readyRecord(PDOStatement $applied, SchemaCatalog $catalog): MigrationRecord
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('quote')->willReturnCallback(static fn (string $value): string => "'" . $value . "'");
        $named = $this->column('platform.schema_migrations');
        $pdo->method('query')->willReturnCallback(static function (string $sql) use ($named, $applied): PDOStatement {
            if (str_contains($sql, 'to_regclass')) {
                return $named;
            }

            return $applied;
        });

        return new MigrationRecord($pdo, $catalog);
    }

    /**
     * @param array<string, string> $applied
     * @param list<string> $sql
     */
    private function tenantPdo(string $schema, array $applied, int &$regclass, array &$sql = [], bool $inside = false): PDO
    {
        $rows = [];
        foreach ($applied as $file => $checksum) {
            $rows[] = ['filename' => $file, 'checksum' => $checksum];
        }
        $pdo = $inside ? $this->createMock(PDO::class) : $this->createStub(PDO::class);
        if ($inside) {
            $pdo->expects($this->never())->method('beginTransaction');
        }
        $pdo->method('quote')->willReturnCallback(static fn (string $value): string => "'" . $value . "'");
        $pdo->method('inTransaction')->willReturn($inside);
        $pdo->method('exec')->willReturnCallback(static function (string $statement) use (&$sql): int {
            $sql[] = $statement;

            return 0;
        });
        $found = $this->rows($rows);
        $locked = $this->column(true);
        $named = $this->column($schema . '.schema_migrations');
        $present = $this->column('found');
        $pdo->method('query')->willReturnCallback(static function (string $statement) use ($locked, $named, $found, $present, &$regclass): PDOStatement {
            if (str_contains($statement, 'pg_try_advisory_lock') || str_contains($statement, 'pg_advisory_unlock')) {
                return $locked;
            }
            if (str_contains($statement, 'to_regclass')) {
                $regclass++;
                if (str_contains($statement, 'schema_migrations')) {
                    return $named;
                }

                return $present;
            }
            if (str_contains($statement, 'schema_migrations')) {
                return $found;
            }

            return $present;
        });
        $schemaRow = $this->column($schema);
        $hit = $this->column(1);
        $pdo->method('prepare')->willReturnCallback(static function (string $statement) use ($schemaRow, $hit): PDOStatement {
            if (str_contains($statement, 'platform.tenants')) {
                return $schemaRow;
            }

            return $hit;
        });

        return $pdo;
    }
}
