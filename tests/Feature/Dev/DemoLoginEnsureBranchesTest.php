<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Dev;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use PerfectConsign\Feature\Dev\DemoLoginEnsure;
use PerfectConsign\Schema\TenantProvisioner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class DemoLoginEnsureBranchesTest extends TestCase
{
    private const SCHEMA = 'tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testAnUnknownSlugIsLeftAlone(): void
    {
        $notes = (new DemoLoginEnsure())->ensureAfterWipe($this->columnPdo(false), 'other');

        self::assertSame([], $notes);
    }

    public function testOwnerPasswordAndMallEmailAreRequired(): void
    {
        $demo = new DemoLoginEnsure();
        $this->withEnv('STALL_OWNER_PASSWORD', null, function () use ($demo): void {
            try {
                $this->invoke($demo, 'ownerPassword');
                self::fail('a missing owner password was accepted');
            } catch (RuntimeException $error) {
                self::assertSame('STALL_OWNER_PASSWORD is required', $error->getMessage());
            }
        });
        $this->withEnv('STALL_OWNER_EMAIL', null, function () use ($demo): void {
            try {
                $this->invoke($demo, 'mallOwnerEmail');
                self::fail('a missing owner email was accepted');
            } catch (RuntimeException $error) {
                self::assertSame('STALL_OWNER_EMAIL is required', $error->getMessage());
            }
        });
        $this->withEnv('STALL_OWNER_EMAIL', 'not-an-email', function () use ($demo): void {
            try {
                $this->invoke($demo, 'mallOwnerEmail');
                self::fail('a bad owner email was accepted');
            } catch (InvalidArgumentException $error) {
                self::assertSame('STALL_OWNER_EMAIL is not an email address', $error->getMessage());
            }
        });
    }

    public function testTheShopEmailCanBeTheDefaultABadAddressOrAlreadyTaken(): void
    {
        $demo = new DemoLoginEnsure();
        $this->withEnv('STALL_SHOP_OWNER_EMAIL', null, function () use ($demo): void {
            $email = $this->invoke($demo, 'shopOwnerEmail', $this->columnPdo(false));
            self::assertSame('desk@cedar-consign.test', $email);
        });
        $this->withEnv('STALL_SHOP_OWNER_EMAIL', 'not-an-email', function () use ($demo): void {
            try {
                $this->invoke($demo, 'shopOwnerEmail', $this->columnPdo(false));
                self::fail('a bad shop email was accepted');
            } catch (InvalidArgumentException $error) {
                self::assertSame('STALL_SHOP_OWNER_EMAIL is not an email address', $error->getMessage());
            }
        });
        $this->withEnv('STALL_SHOP_OWNER_EMAIL', 'taken@example.test', function () use ($demo): void {
            try {
                $this->invoke($demo, 'shopOwnerEmail', $this->columnPdo('1'));
                self::fail('a taken shop email was accepted');
            } catch (RuntimeException $error) {
                self::assertSame('the shop owner email is already used on another sign-in', $error->getMessage());
            }
        });
    }

    public function testModeSqlRefusesABadSchemaAMissingFileAndAFailedExec(): void
    {
        $demo = new DemoLoginEnsure();
        $provisioner = new TenantProvisioner($this->columnPdo(false), dirname(__DIR__, 3) . '/sql');
        try {
            $this->invoke($demo, 'loadModeSql', $this->execPdo('none'), $provisioner, 'public', 'mall', 'mall_tenant.sql');
            self::fail('a bad schema was loaded');
        } catch (RuntimeException $error) {
            self::assertSame('schema name is not a tenant schema', $error->getMessage());
        }

        set_error_handler(static function (int $severity, string $message): bool {
            return $severity === E_WARNING && str_contains($message, 'missing-zcov.sql');
        });
        try {
            $this->invoke(
                $demo,
                'loadModeSql',
                $this->execPdo('none'),
                $provisioner,
                self::SCHEMA,
                'mall',
                'missing-zcov.sql',
            );
            self::fail('a missing sql file was loaded');
        } catch (RuntimeException $error) {
            self::assertSame('could not read missing-zcov.sql', $error->getMessage());
        } finally {
            restore_error_handler();
        }

        try {
            $this->invoke($demo, 'loadModeSql', $this->execPdo('search'), $provisioner, self::SCHEMA, 'mall', 'mall_tenant.sql');
            self::fail('a closed schema was loaded');
        } catch (RuntimeException $error) {
            self::assertSame('could not open the mall schema', $error->getMessage());
        }
        try {
            $this->invoke($demo, 'loadModeSql', $this->execPdo('load'), $provisioner, self::SCHEMA, 'shop', 'shop_tenant.sql');
            self::fail('a failed load was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('could not load shop_tenant.sql', $error->getMessage());
        }
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$args);
    }

    private function columnPdo(mixed $column): PDO
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn($column);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        return $pdo;
    }

    private function execPdo(string $failOn): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturnCallback(static function (string $sql) use ($failOn): int|false {
            if ($failOn === 'search' && str_contains($sql, 'search_path')) {
                return false;
            }
            if ($failOn === 'load' && !str_contains($sql, 'search_path')) {
                return false;
            }

            return 0;
        });

        return $pdo;
    }

    private function withEnv(string $key, ?string $value, callable $run): void
    {
        $previous = getenv($key);
        if ($value === null) {
            putenv($key);
        } else {
            putenv($key . '=' . $value);
        }
        try {
            $run();
        } finally {
            if ($previous === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $previous);
            }
        }
    }
}
