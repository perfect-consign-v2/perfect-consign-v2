<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Dev;

use PerfectConsign\Feature\Dev\DevTenantReset;
use PerfectConsign\Feature\Dev\LocalAdmin;
use PerfectConsign\Feature\Dev\MallTenantReset;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TenantResetTest extends TestCase
{
    public function testLocalAdminOpensTheDockerDatabase(): void
    {
        $host = getenv('STALL_DB_HOST');
        putenv('STALL_DB_HOST');
        try {
            $connection = LocalAdmin::open();
        } finally {
            if (is_string($host) && $host !== '') {
                putenv('STALL_DB_HOST=' . $host);
            }
        }
        self::assertTrue($connection->isLocalRestoreTarget());
        self::assertNotFalse($connection->pdo()->query('SELECT 1'));
    }

    public function testOwnerRowsAndProgressCanBeRead(): void
    {
        $dev = new ReflectionMethod(DevTenantReset::class, 'ownerRow');
        self::assertNull($dev->invoke(new DevTenantReset(), false));
        try {
            $dev->invoke(new DevTenantReset(), ['username' => '', 'password_hash' => 'x']);
            self::fail('an empty owner was accepted');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('dev owner row is invalid', $error->getMessage());
        }
        $mall = new ReflectionMethod(MallTenantReset::class, 'ownerRow');
        self::assertNull($mall->invoke(new MallTenantReset(), false));
        try {
            $mall->invoke(new MallTenantReset(), ['username' => 'owner', 'password_hash' => '']);
            self::fail('an empty mall owner was accepted');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('mall owner row is invalid', $error->getMessage());
        }
        $stream = (new MallTenantReset())->progressStream();
        self::assertIsResource($stream);
        fclose($stream);
    }

    public function testTheDevMallCanBeProvisionedFromEmpty(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $schema = (new DevTenantReset())->load();
        self::assertMatchesRegularExpression('/^tenant_[0-9a-f]{32}$/', $schema);
        $again = (new DevTenantReset())->load();
        self::assertMatchesRegularExpression('/^tenant_[0-9a-f]{32}$/', $again);
    }

    public function testTheDemoMallCanBeReloaded(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $schema = (new MallTenantReset())->load();
        self::assertMatchesRegularExpression('/^tenant_[0-9a-f]{32}$/', $schema);
    }
}
