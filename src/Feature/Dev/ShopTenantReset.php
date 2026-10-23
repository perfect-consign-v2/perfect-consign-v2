<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Dev;

use PDO;
use RuntimeException;

final class ShopTenantReset
{
    public const SLUG = 'shop';

    public function load(): string
    {
        $connection = LocalAdmin::open();
        $this->requireTools();
        $pdo = $connection->pdo();
        \wipeTenant($pdo, self::SLUG, self::SLUG);
        $provisioner = new \TenantProvisioner($pdo, $this->sqlDirectory());
        $schema = $provisioner->provision(self::SLUG, 'Consignment');
        $this->loadSql($pdo, $provisioner, $schema);
        $this->restoreOwner($pdo, $this->owner());

        return $schema;
    }

    private function requireTools(): void
    {
        $bin = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR;
        require_once $bin . 'wipe-tenant.php';
        require_once $bin . 'provision-tenant.php';
    }

    private function sqlDirectory(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'sql';
    }

    /**
     * @return array{username: string, password_hash: string}
     */
    private function owner(): array
    {
        $password = getenv('STALL_OWNER_PASSWORD');
        if (!is_string($password) || $password === '') {
            throw new RuntimeException('STALL_OWNER_PASSWORD is required');
        }
        return [
            'username' => 'owner',
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ];
    }

    private function loadSql(PDO $pdo, \TenantProvisioner $provisioner, string $schema): void
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new RuntimeException('schema name is not a tenant schema');
        }
        $path = $this->sqlDirectory() . DIRECTORY_SEPARATOR . 'shop_tenant.sql';
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('could not read shop_tenant.sql');
        }
        if ($pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions') === false) {
            throw new RuntimeException('could not open the shop schema');
        }
        $loaded = $pdo->exec($provisioner->rewrite($sql, $schema, $provisioner->roleName(self::SLUG, $schema)));
        if ($loaded === false) {
            throw new RuntimeException('could not load shop_tenant.sql');
        }
    }

    /**
     * @param array{username: string, password_hash: string} $owner
     */
    private function restoreOwner(PDO $pdo, array $owner): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO platform.owners (id, tenant_id, username, password_hash)
             SELECT uuidv7(), id, ?, ?
             FROM platform.tenants
             WHERE slug = ?'
        );
        $statement->execute([$owner['username'], $owner['password_hash'], self::SLUG]);
        if ($statement->rowCount() < 1) {
            throw new RuntimeException('the shop owner could not be restored');
        }
    }
}
