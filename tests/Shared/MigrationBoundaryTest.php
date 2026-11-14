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

    public function testEachIntegerOneHoldsTheMigrationLock(): void
    {
        foreach ([1, '1'] as $value) {
            $this->record($this->column($value))->holdMigration();
        }

        foreach ([true, 't', false, 0, '0'] as $value) {
            try {
                $this->record($this->column($value))->holdMigration();
                self::fail('a non-integer lock result was accepted');
            } catch (RuntimeException $error) {
                self::assertSame('a migration is already running', $error->getMessage());
            }
        }
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
        $pdo = $this->createMock(PDO::class);
        $pdo->method('quote')->willReturnCallback(static fn (string $value): string => "'" . $value . "'");
        $named = $this->column('platform.schema_migrations');
        $pdo->method('query')->willReturnCallback(static function (string $sql) use ($named, $applied): PDOStatement {
            if (str_contains($sql, 'to_regclass')) {
                return $named;
            }

            return $applied;
        });
        $pdo->expects($this->never())->method('prepare');
        (new MigrationRecord($pdo, $catalog))->verify('platform');

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
        $locked = $this->column(1);
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
        $seals = 0;
        $pdo = $this->tenantPdo(self::SCHEMA, $applied, $regclass, $sql, false, $seals);

        (new SchemaMigrator($pdo, $catalog))->migrateTenant('mall');

        $joined = implode("\n", $sql);
        self::assertStringContainsString('stall_poster', $joined);
        self::assertStringNotContainsString('poster_0123456789abcdef0123456789abcdef', $joined);
        // Baseline 001 already requires sealed mailbox passwords; no mid-migrate seal step.
        self::assertSame(0, $seals);
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

    public function testANewPlatformInstallsAndThenAppliesWhatWasNotStamped(): void
    {
        $catalog = $this->catalog();
        $sql = [];
        $applied = [];
        $pdo = $this->platformPdo($sql, $applied, ['']);
        $pdo->expects($this->exactly(2))->method('beginTransaction');
        $pdo->expects($this->exactly(2))->method('commit');

        (new SchemaMigrator($pdo, $catalog))->migratePlatform();

        self::assertCount(2, $sql);
        self::assertStringContainsString('000_platform.sql', $this->names($applied));
    }

    public function testInstallStampsThePlatformOnceTheMigrationTableExists(): void
    {
        $catalog = $this->catalog();
        $sql = [];
        $applied = [];
        $pdo = $this->platformPdo($sql, $applied, ['', 'platform.schema_migrations']);
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->expects($this->once())->method('commit');

        (new SchemaMigrator($pdo, $catalog))->migratePlatform();

        self::assertCount(1, $sql);
        self::assertSame($catalog->checksum('000_platform.sql'), $applied['000_platform.sql'] ?? null);
    }

    public function testAnOlderInstalledPlatformIsRefused(): void
    {
        $sql = [];
        $applied = [];
        $pdo = $this->platformPdo($sql, $applied, [''], true);
        $pdo->expects($this->never())->method('exec');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('this database was built by an older schema; create a new database');
        (new SchemaMigrator($pdo, $this->catalog()))->migratePlatform();
    }

    public function testAMissingEventTriggerQueryStillInstalls(): void
    {
        $sql = [];
        $applied = [];
        $pdo = $this->platformPdo($sql, $applied, [''], false, false);
        $pdo->expects($this->exactly(2))->method('commit');

        (new SchemaMigrator($pdo, $this->catalog()))->migratePlatform();

        self::assertCount(2, $sql);
    }

    public function testAFailedPlatformFileRollsBack(): void
    {
        $sql = [];
        $applied = [];
        $pdo = $this->platformPdo($sql, $applied, [''], false, true, true);
        $pdo->expects($this->once())->method('rollBack');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('000_platform.sql was not applied: sql failed');
        (new SchemaMigrator($pdo, $this->catalog()))->migratePlatform();
    }

    public function testAFailedPlatformFileOutsideATransactionDoesNotRollBack(): void
    {
        $sql = [];
        $applied = [];
        $pdo = $this->platformPdo($sql, $applied, [''], false, true, true, true);
        $pdo->expects($this->never())->method('rollBack');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('000_platform.sql was not applied: sql failed');
        (new SchemaMigrator($pdo, $this->catalog()))->migratePlatform();
    }

    public function testAPendingPlatformFileUsesTheCallerTransaction(): void
    {
        $catalog = $this->catalog();
        $sql = [];
        $applied = [];
        $pdo = $this->platformPdo($sql, $applied, ['platform.schema_migrations'], false, true, false, false, true);
        $pdo->expects($this->never())->method('beginTransaction');
        $pdo->expects($this->never())->method('commit');
        $pdo->expects($this->never())->method('rollBack');

        (new SchemaMigrator($pdo, $catalog))->migratePlatform();

        self::assertCount(1, $sql);
        self::assertSame($catalog->checksum('000_platform.sql'), $applied['000_platform.sql'] ?? null);
    }

    public function testAPendingPlatformFileFailureLeavesTheCallerTransaction(): void
    {
        $sql = [];
        $applied = [];
        $pdo = $this->platformPdo($sql, $applied, ['platform.schema_migrations'], false, true, true, false, true);
        $pdo->expects($this->never())->method('rollBack');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('000_platform.sql was not applied: sql failed');
        (new SchemaMigrator($pdo, $this->catalog()))->migratePlatform();
    }

    public function testPlatformAfterRefusesADatabaseThatIsNotReady(): void
    {
        $sql = [];
        $applied = [];
        $pdo = $this->platformPdo($sql, $applied, ['']);
        $pdo->expects($this->never())->method('exec');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('platform is not ready');
        (new SchemaMigrator($pdo, $this->catalog()))->migratePlatformAfter();
    }

    public function testPlatformAfterOnAReadyDatabaseHasNoFurtherFiles(): void
    {
        $sql = [];
        $applied = [];
        $pdo = $this->platformPdo($sql, $applied, ['platform.schema_migrations']);
        $pdo->expects($this->never())->method('exec');

        (new SchemaMigrator($pdo, $this->catalog()))->migratePlatformAfter();

        self::assertSame([], $sql);
    }

    public function testRecordingATenantStampsEachTenantFile(): void
    {
        $catalog = $this->catalog();
        $sql = [];
        $applied = [];
        $pdo = $this->platformPdo($sql, $applied, []);

        (new SchemaMigrator($pdo, $catalog))->recordTenant(self::SCHEMA);

        self::assertSame(['SET search_path TO ' . self::SCHEMA . ', pg_catalog, extensions'], $sql);
        foreach ($catalog->tenantFiles() as $file) {
            self::assertSame($catalog->checksum($file), $applied[$file] ?? null);
        }
    }

    public function testRecordingATenantRefusesASchemaThatIsNotATenant(): void
    {
        $sql = [];
        $applied = [];
        $pdo = $this->platformPdo($sql, $applied, []);
        $pdo->expects($this->never())->method('exec');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('schema name is not a tenant schema');
        (new SchemaMigrator($pdo, $this->catalog()))->recordTenant('nope');
    }

    /**
     * @param list<string> $sql
     * @param array<string, string> $applied
     * @param list<string> $regclass
     */
    private function platformPdo(
        array &$sql,
        array &$applied,
        array $regclass,
        bool $installed = false,
        bool $eventQuery = true,
        bool $execError = false,
        bool $stayIdle = false,
        bool $alreadyOpen = false,
    ): PDO {
        $regclassIndex = 0;
        $open = $alreadyOpen;
        $rows = $this->createStub(PDOStatement::class);
        $rows->method('fetchAll')->willReturnCallback(static function (int $mode = 0) use (&$applied): array {
            return array_map(
                static fn (string $checksum, string $file): array => ['filename' => $file, 'checksum' => $checksum],
                $applied,
                array_keys($applied),
            );
        });
        $pdo = $this->createMock(PDO::class);
        $pdo->method('quote')->willReturnCallback(static fn (string $value): string => "'" . $value . "'");
        $pdo->method('inTransaction')->willReturnCallback(static function () use (&$open, $stayIdle, $alreadyOpen): bool {
            if ($stayIdle) {
                return false;
            }

            return $alreadyOpen || $open;
        });
        $pdo->method('beginTransaction')->willReturnCallback(static function () use (&$open): bool {
            $open = true;

            return true;
        });
        $pdo->method('commit')->willReturnCallback(static function () use (&$open): bool {
            $open = false;

            return true;
        });
        $pdo->method('rollBack')->willReturnCallback(static function () use (&$open): bool {
            $open = false;

            return true;
        });
        $pdo->method('exec')->willReturnCallback(static function (string $statement) use (&$sql, $execError): int {
            if ($execError) {
                throw new RuntimeException('sql failed');
            }
            $sql[] = $statement;

            return 0;
        });
        $pdo->method('prepare')->willReturnCallback(function () use (&$applied): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(static function (array $params) use (&$applied): bool {
                $file = $params[0];
                $checksum = $params[1];
                if (is_string($file) && is_string($checksum)) {
                    $applied[$file] = $checksum;
                }

                return true;
            });

            return $statement;
        });
        $pdo->expects($this->atLeastOnce())->method('query')->willReturnCallback(function (string $statement) use (
            $rows,
            &$regclassIndex,
            $regclass,
            $installed,
            $eventQuery,
        ): PDOStatement|false {
            if (str_contains($statement, 'pg_try_advisory_lock')
                || str_contains($statement, 'pg_advisory_unlock')
                || $statement === 'SELECT 1'
            ) {
                return $this->column(1);
            }
            if (str_contains($statement, 'pg_event_trigger')) {
                if (!$eventQuery) {
                    return false;
                }

                return $this->column($installed ? 1 : false);
            }
            if (str_contains($statement, 'to_regclass')) {
                $value = $regclass[$regclassIndex] ?? $regclass[count($regclass) - 1] ?? '';
                if ($regclassIndex < count($regclass) - 1) {
                    $regclassIndex++;
                }

                return $this->column($value);
            }
            if (str_contains($statement, 'schema_migrations')) {
                return $rows;
            }

            throw new RuntimeException('unexpected ' . $statement);
        });

        return $pdo;
    }

    /**
     * @param array<string, string> $applied
     */
    private function names(array $applied): string
    {
        return implode(',', array_keys($applied));
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
    private function tenantPdo(string $schema, array $applied, int &$regclass, array &$sql = [], bool $inside = false, int &$seals = 0): PDO
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
        $locked = $this->column(1);
        $named = $this->column($schema . '.schema_migrations');
        $present = $this->column('found');
        $pdo->method('query')->willReturnCallback(static function (string $statement) use ($locked, $named, $found, $present, &$regclass, &$seals): PDOStatement {
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
            if (str_contains($statement, 'gmail_app_password FROM mall')) {
                $seals++;
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
