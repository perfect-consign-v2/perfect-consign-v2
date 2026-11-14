<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use PerfectConsign\Schema\TenantProvisioner;
use PerfectConsign\Tests\Support\ScriptPdo;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TenantProvisionerCoverageTest extends TestCase
{
    private const ID = '01900000-0000-7000-8000-0000000000c1';

    public function testProvisionInstallsASchemaWithoutApplyingSqlToAServer(): void
    {
        $pdo = $this->ready();
        $schema = (new TenantProvisioner($pdo, $this->sql()))->provision('zcov-ready', 'Coverage Mall');

        self::assertSame('tenant_' . str_replace('-', '', self::ID), $schema);
    }

    public function testAMissingLedgerFileRollsBackAndDropsTheNewRole(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zcov-sql-' . bin2hex(random_bytes(4));
        if (!mkdir($directory, 0700) && !is_dir($directory)) {
            self::fail('the empty sql directory could not be created');
        }
        $pdo = $this->ready();
        set_error_handler(static function (int $severity, string $message): bool {
            return $severity === E_WARNING && str_contains($message, '001_tenant.sql');
        });
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('could not read 001_tenant.sql');
            (new TenantProvisioner($pdo, $directory))->provision('zcov-gap', 'Gap');
        } finally {
            restore_error_handler();
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testBeginFailureDoesNotDropAnExistingRole(): void
    {
        $pdo = $this->ready();
        $pdo->onPrepare('pg_roles', [['present' => '1']]);
        $pdo->throwOnBegin = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('begin failed');
        (new TenantProvisioner($pdo, $this->sql()))->provision('zcov-begin', 'Begin');
    }

    public function testATenantIdQueryMustReturnAString(): void
    {
        $missing = $this->ready();
        $missing->onceQuery('uuidv7', false);
        try {
            (new TenantProvisioner($missing, $this->sql()))->provision('zcov-id', 'Id');
            self::fail('a missing id was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('could not create a tenant id', $error->getMessage());
        }

        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetch')->willReturn(['id' => 12]);
        $found = $this->createStub(PDOStatement::class);
        $found->method('fetchColumn')->willReturn('1');
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturnCallback(static function (string $sql) use ($statement, $found): PDOStatement {
            if (str_contains($sql, 'stall_backup')) {
                return $found;
            }

            return $statement;
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not create a tenant id');
        (new TenantProvisioner($pdo, $this->sql()))->provision('zcov-id', 'Id');
    }

    public function testNamesRolesAndGrantsAreRefusedWhenTheyAreWrong(): void
    {
        $pdo = new ScriptPdo();
        $provisioner = new TenantProvisioner($pdo, $this->sql());
        $files = TenantProvisioner::ledgerFiles();
        self::assertContains('001_tenant.sql', $files);
        self::assertSame('stall_poster', $provisioner->roleName('mall', 'tenant_' . str_repeat('a', 32)));
        self::assertSame(
            'poster_' . str_repeat('b', 32),
            $provisioner->roleName('zcov-role', 'tenant_' . str_repeat('b', 32))
        );
        $rewritten = $provisioner->rewrite('SELECT public.booths', 'tenant_' . str_repeat('c', 32), 'poster_c');
        self::assertStringContainsString('tenant_' . str_repeat('c', 32), $rewritten);

        try {
            $provisioner->provision('Admin', 'Admin');
            self::fail('an uppercase slug was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a tenant slug must be a short lowercase name', $error->getMessage());
        }
        try {
            $provisioner->provision('admin', 'Admin');
            self::fail('a reserved slug was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('admin is reserved for the platform', $error->getMessage());
        }
        try {
            $provisioner->provision('zcov-name', '  Mall');
            self::fail('a padded name was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a tenant display name is required', $error->getMessage());
        }
        try {
            $provisioner->provision('zcov-name', '');
            self::fail('an empty name was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a tenant display name is required', $error->getMessage());
        }
        try {
            $provisioner->applyLedger('public', 'stall_poster');
            self::fail('a public schema was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('schema name is not a tenant schema', $error->getMessage());
        }
        try {
            $provisioner->applyLedger('tenant_' . str_repeat('d', 32), 'Bad Role');
            self::fail('a bad role was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('poster role name is invalid', $error->getMessage());
        }
        try {
            $provisioner->grantApp('Bad');
            self::fail('a bad grant role was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('poster role name is invalid', $error->getMessage());
        }
        try {
            $provisioner->grantBackup('nope', 'stall_poster');
            self::fail('a bad backup schema was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('schema name is not a tenant schema', $error->getMessage());
        }

        $blind = $this->createStub(PDO::class);
        $blind->method('query')->willReturn(false);
        try {
            (new TenantProvisioner($blind, $this->sql()))->grantApp('stall_poster');
            self::fail('a missing stall_app was granted');
        } catch (RuntimeException $error) {
            self::assertSame('stall_app is missing. Apply sql/000_platform.sql first.', $error->getMessage());
        }
        $empty = $this->createStub(PDOStatement::class);
        $empty->method('fetchColumn')->willReturn(false);
        $quiet = $this->createStub(PDO::class);
        $quiet->method('query')->willReturn($empty);
        try {
            (new TenantProvisioner($quiet, $this->sql()))->grantBackup('tenant_' . str_repeat('e', 32), 'stall_poster');
            self::fail('a missing stall_backup was granted');
        } catch (RuntimeException $error) {
            self::assertSame('stall_backup is missing. Apply sql/000_platform.sql first.', $error->getMessage());
        }
    }

    public function testGrantAppSkipsAMissingWorker(): void
    {
        $pdo = new ScriptPdo();
        $pdo->onQuery('stall_app', [['present' => '1']]);
        $pdo->onQuery('stall_worker', []);
        (new TenantProvisioner($pdo, $this->sql()))->grantApp('stall_poster');
        self::assertSame('stall_poster', 'stall_poster');
    }

    private function ready(): ScriptPdo
    {
        $pdo = new ScriptPdo();
        $pdo->onQuery('stall_backup', [['present' => '1']]);
        $pdo->onQuery('stall_app', [['present' => '1']]);
        $pdo->onQuery('stall_worker', [['present' => '1']]);
        $pdo->onQuery('uuidv7', [['id' => self::ID]]);
        $pdo->onQuery('pg_try_advisory_lock', [['held' => '1']]);
        $pdo->onQuery('pg_advisory_unlock', [['ok' => '1']]);

        return $pdo;
    }

    private function sql(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'sql';
    }
}
