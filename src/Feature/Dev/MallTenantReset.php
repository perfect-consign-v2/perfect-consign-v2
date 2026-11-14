<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Dev;

use PDO;
use RuntimeException;

final class MallTenantReset
{
    public const SLUG = 'mall';

    private bool $hold = false;

    private ?PDO $injected = null;

    public function hold(): void
    {
        $this->hold = true;
    }

    public function usePdo(PDO $pdo): void
    {
        $this->injected = $pdo;
    }

    public function load(): string
    {
        if ($this->hold) {
            return 'tenant_' . str_repeat('ab', 16);
        }
        $pdo = $this->injected;
        if (!$pdo instanceof PDO) {
            $connection = LocalAdmin::open();
            $pdo = $connection->pdo();
        }
        $this->requireTools();
        $owners = $this->owners($pdo);
        $owners = $this->withOwner($pdo, $owners);
        \wipeTenant($pdo, self::SLUG, self::SLUG);
        $provisioner = new \TenantProvisioner($pdo, $this->sqlDirectory());
        $schema = $provisioner->provision(self::SLUG, 'Mall');
        try {
            $this->keepProgress();
            (new \MallDemo($pdo, $schema))->load();
        } finally {
            foreach ($owners as $owner) {
                $this->restoreOwner($pdo, $owner);
            }
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
    public function progressStream(string $target = 'php://temp')
    {
        $stream = fopen($target, 'w');
        if ($stream === false) {
            throw new RuntimeException('could not open a progress stream');
        }

        return $stream;
    }

    /**
     * @param list<array{username: string, password_hash: string, email: ?string}> $owners
     * @return list<array{username: string, password_hash: string, email: ?string}>
     */
    private function withOwner(PDO $pdo, array $owners): array
    {
        if ($owners === []) {
            return [$this->replacementOwner($pdo)];
        }

        return $owners;
    }

    /**
     * @return array{username: string, password_hash: string, email: ?string}
     */
    private function replacementOwner(PDO $pdo): array
    {
        $password = getenv('STALL_OWNER_PASSWORD');
        if (!is_string($password) || $password === '') {
            throw new RuntimeException('STALL_OWNER_PASSWORD is required');
        }
        return [
            'username' => 'owner',
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'email' => $this->ownerEmailFromEnv($pdo),
        ];
    }

    private function ownerEmailFromEnv(?PDO $pdo = null): ?string
    {
        $email = getenv('STALL_OWNER_EMAIL');
        if (!is_string($email) || trim($email) === '') {
            return null;
        }
        $email = strtolower(trim($email));
        if ($pdo instanceof PDO && $this->emailTakenByOtherTenant($pdo, $email, self::SLUG)) {
            return null;
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
     * @return list<array{username: string, password_hash: string, email: ?string}>
     */
    private function owners(PDO $pdo): array
    {
        $statement = $pdo->prepare(
            'SELECT owners.username, owners.password_hash, owners.email
             FROM platform.owners AS owners
             JOIN platform.tenants AS tenants ON tenants.id = owners.tenant_id
             WHERE tenants.slug = ?
             ORDER BY lower(owners.username)'
        );
        $statement->execute([self::SLUG]);
        $owners = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $parsed = $this->ownerRow($row);
            if ($parsed !== null) {
                $owners[] = $parsed;
            }
        }

        return $owners;
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
            // Keep a null email. Filling STALL_OWNER_EMAIL here can steal another
            // tenant's unique address after ProbeMall or set-owner leaves that env set.
            $email = null;
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
        $email = $owner['email'];
        if (is_string($email) && $this->emailTakenByOtherTenant($pdo, $email, self::SLUG)) {
            $email = null;
        }
        $statement = $pdo->prepare(
            'INSERT INTO platform.owners (id, tenant_id, username, password_hash, email, email_verified_at)
             SELECT uuidv7(), id, ?, ?, ?, CASE WHEN ?::text IS NULL THEN NULL ELSE clock_timestamp() END
             FROM platform.tenants
             WHERE slug = ?'
        );
        $statement->execute([
            $owner['username'],
            $owner['password_hash'],
            $email,
            $email,
            self::SLUG,
        ]);
        if ($statement->rowCount() < 1) {
            throw new RuntimeException('the mall owner could not be restored');
        }
    }
}
