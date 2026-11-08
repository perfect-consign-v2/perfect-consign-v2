<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Dev;

use PerfectConsign\Feature\Dev\DemoLoginEnsure;
use PerfectConsign\Feature\Dev\LocalAdmin;
use PerfectConsign\Feature\Dev\ShopTenantReset;
use PDO;
use PHPUnit\Framework\TestCase;

final class DemoLoginEnsureTest extends TestCase
{
    public function testWipedShopIsRestoredWithEnvPassword(): void
    {
        $password = getenv('STALL_OWNER_PASSWORD');
        if (!is_string($password) || $password === '') {
            self::markTestSkipped('STALL_OWNER_PASSWORD is required');
        }
        $pdo = LocalAdmin::open()->pdo();
        $root = dirname(__DIR__, 3);
        require_once $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'wipe-tenant.php';
        wipeTenant($pdo, DemoLoginEnsure::SHOP_SLUG, DemoLoginEnsure::SHOP_SLUG);
        self::assertFalse($this->tenantExists($pdo, DemoLoginEnsure::SHOP_SLUG));

        $notes = (new DemoLoginEnsure())->ensureAfterWipe($pdo, DemoLoginEnsure::SHOP_SLUG);

        self::assertTrue($this->tenantExists($pdo, DemoLoginEnsure::SHOP_SLUG));
        self::assertNotSame([], $notes);
        $hash = $this->ownerHash($pdo, DemoLoginEnsure::SHOP_SLUG);
        self::assertNotFalse($hash);
        self::assertTrue(password_verify($password, $hash));
        self::assertSame(
            $this->expectedShopEmail(),
            $this->ownerEmail($pdo, DemoLoginEnsure::SHOP_SLUG),
        );
    }

    public function testEnsureIsIdempotentWhenShopAlreadyExists(): void
    {
        $password = getenv('STALL_OWNER_PASSWORD');
        if (!is_string($password) || $password === '') {
            self::markTestSkipped('STALL_OWNER_PASSWORD is required');
        }
        $pdo = LocalAdmin::open()->pdo();
        (new DemoLoginEnsure())->ensureAfterWipe($pdo, DemoLoginEnsure::SHOP_SLUG);
        $before = $this->credentialsVersion($pdo, DemoLoginEnsure::SHOP_SLUG);
        $notes = (new DemoLoginEnsure())->ensureAfterWipe($pdo, DemoLoginEnsure::SHOP_SLUG);
        self::assertContains('shop owner unchanged', $notes);
        self::assertSame($before, $this->credentialsVersion($pdo, DemoLoginEnsure::SHOP_SLUG));
    }

    private function tenantExists(PDO $pdo, string $slug): bool
    {
        $statement = $pdo->prepare('SELECT 1 FROM platform.tenants WHERE slug = ?');
        $statement->execute([$slug]);

        return $statement->fetchColumn() !== false;
    }

    private function ownerHash(PDO $pdo, string $slug): string|false
    {
        $statement = $pdo->prepare(
            'SELECT o.password_hash
             FROM platform.owners o
             INNER JOIN platform.tenants t ON t.id = o.tenant_id
             WHERE t.slug = ? AND o.username = ?'
        );
        $statement->execute([$slug, DemoLoginEnsure::OWNER_USERNAME]);
        $hash = $statement->fetchColumn();

        return is_string($hash) ? $hash : false;
    }

    private function ownerEmail(PDO $pdo, string $slug): ?string
    {
        $statement = $pdo->prepare(
            'SELECT o.email
             FROM platform.owners o
             INNER JOIN platform.tenants t ON t.id = o.tenant_id
             WHERE t.slug = ? AND o.username = ?'
        );
        $statement->execute([$slug, DemoLoginEnsure::OWNER_USERNAME]);
        $email = $statement->fetchColumn();

        return is_string($email) ? strtolower($email) : null;
    }

    private function credentialsVersion(PDO $pdo, string $slug): int
    {
        $statement = $pdo->prepare(
            'SELECT o.credentials_version
             FROM platform.owners o
             INNER JOIN platform.tenants t ON t.id = o.tenant_id
             WHERE t.slug = ? AND o.username = ?'
        );
        $statement->execute([$slug, DemoLoginEnsure::OWNER_USERNAME]);
        $version = $statement->fetchColumn();
        self::assertNotFalse($version);

        return (int) $version;
    }

    private function expectedShopEmail(): string
    {
        $configured = getenv('STALL_SHOP_OWNER_EMAIL');
        if (is_string($configured) && trim($configured) !== '') {
            return strtolower(trim($configured));
        }

        return ShopTenantReset::OWNER_EMAIL;
    }
}
