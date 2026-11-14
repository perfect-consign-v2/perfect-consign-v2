<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Dev;

use PDO;
use RuntimeException;

final class ShopTenantReset
{
    public const SLUG = 'shop';

    /** Default sign-in email (platform-unique; mall uses STALL_OWNER_EMAIL). */
    public const OWNER_EMAIL = 'desk@cedar-consign.test';

    private bool $hold = false;

    private ?string $sqlRoot = null;

    public function hold(): void
    {
        $this->hold = true;
    }

    public function useSqlRoot(string $directory): void
    {
        $this->sqlRoot = $directory;
    }

    /** Local HTTP/dev reset only — refuses non-local restore targets. */
    public function load(): string
    {
        if ($this->hold) {
            return 'tenant_' . str_repeat('ab', 16);
        }

        return $this->rebuild(LocalAdmin::open()->pdo());
    }

    /**
     * CLI seed path (`bin/seed-mall.php`) after mall wipe/provision on the same PDO.
     * Not for HTTP controllers.
     */
    public function rebuild(PDO $pdo): string
    {
        $this->requireTools();
        \wipeTenant($pdo, self::SLUG, self::SLUG);
        $provisioner = new \TenantProvisioner($pdo, $this->sqlDirectory());
        $schema = $provisioner->provision(self::SLUG, 'Consignment');
        $this->loadSql($pdo, $provisioner, $schema);
        // Plant sign-in before the long demo post so a hung/killed seed still leaves /shop usable.
        $this->restoreOwner($pdo, $this->owner($pdo));
        (new \ShopDemo($pdo, $schema))->load();

        return $schema;
    }

    private function requireTools(): void
    {
        $bin = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR;
        require_once $bin . 'wipe-tenant.php';
        require_once $bin . 'provision-tenant.php';
        require_once $bin . 'ShopDemo.php';
    }

    private function sqlDirectory(): string
    {
        return $this->sqlRoot ?? (dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'sql');
    }

    /**
     * @return array{username: string, password_hash: string, email: string}
     */
    private function owner(PDO $pdo): array
    {
        $password = getenv('STALL_OWNER_PASSWORD');
        if (!is_string($password) || $password === '') {
            throw new RuntimeException('STALL_OWNER_PASSWORD is required');
        }
        return [
            'username' => 'owner',
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'email' => $this->ownerEmail($pdo),
        ];
    }

    private function ownerEmail(PDO $pdo): string
    {
        $configured = getenv('STALL_SHOP_OWNER_EMAIL');
        if (is_string($configured) && trim($configured) !== '') {
            $email = strtolower(trim($configured));
        } else {
            $email = self::OWNER_EMAIL;
        }
        if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1 || strlen($email) > 200) {
            throw new RuntimeException('STALL_SHOP_OWNER_EMAIL is not an email address');
        }
        if ($this->emailTakenByOtherTenant($pdo, $email)) {
            throw new RuntimeException('the shop owner email is already used on another sign-in');
        }

        return $email;
    }

    private function emailTakenByOtherTenant(PDO $pdo, string $email): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1
             FROM platform.owners AS owners
             JOIN platform.tenants AS tenants ON tenants.id = owners.tenant_id
             WHERE lower(owners.email) = ?
               AND tenants.slug <> ?
             LIMIT 1'
        );
        $statement->execute([$email, self::SLUG]);

        return $statement->fetchColumn() !== false;
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
     * @param array{username: string, password_hash: string, email: string} $owner
     */
    private function restoreOwner(PDO $pdo, array $owner): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO platform.owners (id, tenant_id, username, password_hash, email, email_verified_at)
             SELECT uuidv7(), id, ?, ?, ?, clock_timestamp()
             FROM platform.tenants
             WHERE slug = ?'
        );
        try {
            $statement->execute([
                $owner['username'],
                $owner['password_hash'],
                $owner['email'],
                self::SLUG,
            ]);
        } catch (\PDOException $error) {
            $state = $error->errorInfo[0] ?? '';
            if ($state === '23505') {
                throw new RuntimeException('the shop owner email is already used on another sign-in', 0, $error);
            }
            throw $error;
        }
        if ($statement->rowCount() < 1) {
            throw new RuntimeException('the shop owner could not be restored');
        }
    }
}
