<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Dev;

use PDO;
use RuntimeException;

final class MallTenantReset
{
    public const SLUG = 'mall';

    public function load(): string
    {
        $connection = LocalAdmin::open();
        $this->requireTools();
        $pdo = $connection->pdo();
        $owner = $this->owner($pdo) ?? $this->replacementOwner();
        \wipeTenant($pdo, self::SLUG, self::SLUG);
        $provisioner = new \TenantProvisioner($pdo, $this->sqlDirectory());
        $schema = $provisioner->provision(self::SLUG, 'Mall');
        try {
            $this->keepProgress();
            (new \MallDemo($pdo, $schema))->load();
        } finally {
            $this->restoreOwner($pdo, $owner);
        }
        (new ShopTenantReset())->load();

        return $schema;
    }

    private function keepProgress(): void
    {
        if (defined('STDOUT')) {
            return;
        }
        define('STDOUT', $this->progressStream());
    }

    /**
     * @return resource
     */
    public function progressStream()
    {
        $stream = fopen('php://temp', 'w');
        if ($stream === false) {
            throw new RuntimeException('could not open a progress stream');
        }

        return $stream;
    }

    /**
     * @return array{username: string, password_hash: string, email: ?string}
     */
    private function replacementOwner(): array
    {
        $password = getenv('STALL_OWNER_PASSWORD');
        if (!is_string($password) || $password === '') {
            throw new RuntimeException('STALL_OWNER_PASSWORD is required');
        }
        return [
            'username' => 'owner',
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'email' => $this->ownerEmailFromEnv(),
        ];
    }

    private function ownerEmailFromEnv(): ?string
    {
        $email = getenv('STALL_OWNER_EMAIL');
        if (!is_string($email) || trim($email) === '') {
            return null;
        }

        return strtolower(trim($email));
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
     * @return array{username: string, password_hash: string, email: ?string}|null
     */
    private function owner(PDO $pdo): ?array
    {
        $statement = $pdo->prepare(
            'SELECT owners.username, owners.password_hash, owners.email
             FROM platform.owners AS owners
             JOIN platform.tenants AS tenants ON tenants.id = owners.tenant_id
             WHERE tenants.slug = ?'
        );
        $statement->execute([self::SLUG]);

        return $this->ownerRow($statement->fetch(PDO::FETCH_ASSOC));
    }

    /**
     * @return array{username: string, password_hash: string, email: ?string}|null
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
        $email = $row['email'] ?? null;
        if (!is_string($email) || trim($email) === '') {
            $email = $this->ownerEmailFromEnv();
        } else {
            $email = strtolower(trim($email));
        }

        return ['username' => $username, 'password_hash' => $passwordHash, 'email' => $email];
    }

    /**
     * @param array{username: string, password_hash: string, email: ?string} $owner
     */
    private function restoreOwner(PDO $pdo, array $owner): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO platform.owners (id, tenant_id, username, password_hash, email, email_verified_at)
             SELECT uuidv7(), id, ?, ?, ?, CASE WHEN ?::text IS NULL THEN NULL ELSE clock_timestamp() END
             FROM platform.tenants
             WHERE slug = ?'
        );
        $statement->execute([
            $owner['username'],
            $owner['password_hash'],
            $owner['email'],
            $owner['email'],
            self::SLUG,
        ]);
        if ($statement->rowCount() < 1) {
            throw new RuntimeException('the mall owner could not be restored');
        }
    }
}
