<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Dev;

use PerfectConsign\Feature\Backup\BackupConnection;
use PDO;
use RuntimeException;

final class MallTenantReset
{
    public const SLUG = 'mall';

    public function load(): string
    {
        $connection = BackupConnection::admin();
        if (!$connection->isLocalRestoreTarget()) {
            throw new RuntimeException('test data reset is only allowed on the local consign database');
        }
        $this->requireTools();
        $pdo = $connection->pdo();
        $owner = $this->owner($pdo);
        \wipeTenant($pdo, self::SLUG, self::SLUG);
        $provisioner = new \TenantProvisioner($pdo, $this->sqlDirectory());
        $schema = $provisioner->provision(self::SLUG, 'Mall');
        (new \MallDemo($pdo, $schema))->load();
        $this->restoreOwner($pdo, $owner);

        return $schema;
    }

    private function requireTools(): void
    {
        $bin = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR;
        require_once $bin . 'wipe-tenant.php';
        require_once $bin . 'provision-tenant.php';
        require_once $bin . 'MallDemo.php';
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
            throw new RuntimeException('the mall owner row is invalid');
        }

        return ['username' => $username, 'password_hash' => $passwordHash];
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
            throw new RuntimeException('the mall owner could not be restored');
        }
    }
}
