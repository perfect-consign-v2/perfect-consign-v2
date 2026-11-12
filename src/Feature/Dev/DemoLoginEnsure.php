<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Dev;

use InvalidArgumentException;
use PDO;
use PerfectConsign\Schema\TenantProvisioner;
use RuntimeException;

/**
 * After a local wipe, restores mall + shop so email sign-in from .env still works.
 * Does not load demo books — use seed-mall for that.
 */
final class DemoLoginEnsure
{
    public const MALL_SLUG = 'mall';

    public const SHOP_SLUG = 'shop';

    public const OWNER_USERNAME = 'owner';

    /**
     * @return list<string> human-readable steps taken
     */
    public function ensure(?PDO $pdo = null): array
    {
        $pdo ??= LocalAdmin::open()->pdo();
        $notes = [];
        $notes = array_merge($notes, $this->ensureMall($pdo));
        $notes = array_merge($notes, $this->ensureShop($pdo));

        return $notes;
    }

    /**
     * Re-create a wiped demo store and plant its owner. Other slugs are ignored.
     *
     * @return list<string>
     */
    public function ensureAfterWipe(PDO $pdo, string $slug): array
    {
        return match ($slug) {
            self::MALL_SLUG => $this->ensureMall($pdo),
            self::SHOP_SLUG => $this->ensureShop($pdo),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function ensureMall(PDO $pdo): array
    {
        $notes = [];
        if (!$this->tenantExists($pdo, self::MALL_SLUG)) {
            $provisioner = new TenantProvisioner($pdo, $this->sqlDirectory());
            $schema = $provisioner->provision(self::MALL_SLUG, 'Mall');
            $this->loadMallSql($pdo, $provisioner, $schema);
            $notes[] = 'provisioned mall as ' . $schema;
        }
        $outcome = (new OwnerCredentialPlant($pdo))->plant(
            self::MALL_SLUG,
            self::OWNER_USERNAME,
            $this->ownerPassword(),
            $this->mallOwnerEmail(),
        );
        $notes[] = 'mall owner ' . $outcome->value;

        return $notes;
    }

    /**
     * @return list<string>
     */
    private function ensureShop(PDO $pdo): array
    {
        $notes = [];
        if (!$this->tenantExists($pdo, self::SHOP_SLUG)) {
            $provisioner = new TenantProvisioner($pdo, $this->sqlDirectory());
            $schema = $provisioner->provision(self::SHOP_SLUG, 'Consignment');
            $this->loadShopSql($pdo, $provisioner, $schema);
            $notes[] = 'provisioned shop as ' . $schema;
        }
        $outcome = (new OwnerCredentialPlant($pdo))->plant(
            self::SHOP_SLUG,
            self::OWNER_USERNAME,
            $this->ownerPassword(),
            $this->shopOwnerEmail($pdo),
        );
        $notes[] = 'shop owner ' . $outcome->value;

        return $notes;
    }

    private function tenantExists(PDO $pdo, string $slug): bool
    {
        $statement = $pdo->prepare('SELECT 1 FROM platform.tenants WHERE slug = ?');
        $statement->execute([$slug]);

        return $statement->fetchColumn() !== false;
    }

    private function ownerPassword(): string
    {
        $password = getenv('STALL_OWNER_PASSWORD');
        if (!is_string($password) || $password === '') {
            throw new RuntimeException('STALL_OWNER_PASSWORD is required');
        }

        return $password;
    }

    private function mallOwnerEmail(): string
    {
        $email = getenv('STALL_OWNER_EMAIL');
        if (!is_string($email) || trim($email) === '') {
            throw new RuntimeException('STALL_OWNER_EMAIL is required');
        }
        $email = strtolower(trim($email));
        if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1 || strlen($email) > 200) {
            throw new InvalidArgumentException('STALL_OWNER_EMAIL is not an email address');
        }

        return $email;
    }

    private function shopOwnerEmail(PDO $pdo): string
    {
        $configured = getenv('STALL_SHOP_OWNER_EMAIL');
        if (is_string($configured) && trim($configured) !== '') {
            $email = strtolower(trim($configured));
        } else {
            $email = ShopTenantReset::OWNER_EMAIL;
        }
        if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1 || strlen($email) > 200) {
            throw new InvalidArgumentException('STALL_SHOP_OWNER_EMAIL is not an email address');
        }
        if ($this->emailTakenByOtherTenant($pdo, $email, self::SHOP_SLUG)) {
            throw new RuntimeException('the shop owner email is already used on another sign-in');
        }

        return $email;
    }

    private function emailTakenByOtherTenant(PDO $pdo, string $email, string $slug): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1
             FROM platform.owners AS owners
             JOIN platform.tenants AS tenants ON tenants.id = owners.tenant_id
             WHERE lower(owners.email) = ?
               AND tenants.slug <> ?
             LIMIT 1'
        );
        $statement->execute([$email, $slug]);

        return $statement->fetchColumn() !== false;
    }

    private function loadMallSql(PDO $pdo, TenantProvisioner $provisioner, string $schema): void
    {
        $this->loadModeSql($pdo, $provisioner, $schema, self::MALL_SLUG, 'mall_tenant.sql');
    }

    private function loadShopSql(PDO $pdo, TenantProvisioner $provisioner, string $schema): void
    {
        $this->loadModeSql($pdo, $provisioner, $schema, self::SHOP_SLUG, 'shop_tenant.sql');
    }

    private function loadModeSql(
        PDO $pdo,
        TenantProvisioner $provisioner,
        string $schema,
        string $slug,
        string $file,
    ): void {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new RuntimeException('schema name is not a tenant schema');
        }
        $path = $this->sqlDirectory() . DIRECTORY_SEPARATOR . $file;
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('could not read ' . $file);
        }
        if ($pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions') === false) {
            throw new RuntimeException('could not open the ' . $slug . ' schema');
        }
        $loaded = $pdo->exec($provisioner->rewrite($sql, $schema, $provisioner->roleName($slug, $schema)));
        if ($loaded === false) {
            throw new RuntimeException('could not load ' . $file);
        }
    }

    private function sqlDirectory(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'sql';
    }
}
