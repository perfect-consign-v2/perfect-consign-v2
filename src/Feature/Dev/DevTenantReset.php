<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Dev;

use PDO;
use RuntimeException;

final class DevTenantReset
{
    public const SLUG = 'dev';

    public function load(): string
    {
        $connection = LocalAdmin::open();
        $this->requireTools();
        $pdo = $connection->pdo();
        $owner = $this->owner($pdo);
        \wipeTenant($pdo, self::SLUG, self::SLUG);
        $provisioner = new \TenantProvisioner($pdo, $this->sqlDirectory());
        $schema = $provisioner->provision(self::SLUG, 'Dev');
        $this->loadSql($pdo, $provisioner, $schema);
        $this->restoreOwner($pdo, $owner);

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
     * @return array{username: string, password_hash: string}|null
     */
    private function owner(PDO $pdo): ?array
    {
        $statement = $pdo->prepare(
            'SELECT owners.username, owners.password_hash
             FROM platform.owners AS owners
             JOIN platform.tenants AS tenants ON tenants.id = owners.tenant_id
             WHERE tenants.slug = ?'
        );
        $statement->execute([self::SLUG]);

        return $this->ownerRow($statement->fetch(PDO::FETCH_ASSOC));
    }

    /**
     * @return array{username: string, password_hash: string}|null
     */
    private function ownerRow(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $username = $row['username'] ?? null;
        $passwordHash = $row['password_hash'] ?? null;
        if (!is_string($username) || $username === '' || !is_string($passwordHash) || $passwordHash === '') {
            throw new RuntimeException('the dev owner row is invalid');
        }

        return ['username' => $username, 'password_hash' => $passwordHash];
    }

    private function loadSql(PDO $pdo, \TenantProvisioner $provisioner, string $schema): void
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new RuntimeException('schema name is not a tenant schema');
        }
        $path = $this->sqlDirectory() . DIRECTORY_SEPARATOR . 'dev_tenant.sql';
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('could not read dev_tenant.sql');
        }
        if ($pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions') === false) {
            throw new RuntimeException('could not open the dev schema');
        }
        $loaded = $pdo->exec($provisioner->rewrite($sql, $schema, $provisioner->roleName(self::SLUG, $schema)));
        if ($loaded === false) {
            throw new RuntimeException('could not load dev_tenant.sql');
        }
    }

    /**
     * @param array{username: string, password_hash: string}|null $owner
     */
    private function restoreOwner(PDO $pdo, ?array $owner): void
    {
        if ($owner === null) {
            return;
        }
        $statement = $pdo->prepare(
            'INSERT INTO platform.owners (id, tenant_id, username, password_hash)
             SELECT uuidv7(), id, ?, ?
             FROM platform.tenants
             WHERE slug = ?'
        );
        $statement->execute([$owner['username'], $owner['password_hash'], self::SLUG]);
        if ($statement->rowCount() < 1) {
            throw new RuntimeException('the dev owner could not be restored');
        }
    }
}
