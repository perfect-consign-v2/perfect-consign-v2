<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;
use Psr\Log\LoggerInterface;

final class StaffSignIn
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Session $session,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function attempt(TenantRecord $tenant, string $username, string $password): bool
    {
        $row = $this->lookup($tenant, $username);
        if ($row === null || !$this->passwordMatches($row, $password) || !$this->knownRole($row)) {
            $this->refuse($tenant, $username);

            return false;
        }
        $this->openSession($tenant, $username, $row);

        return true;
    }

    /**
     * @return array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null}|null
     */
    private function lookup(TenantRecord $tenant, string $username): ?array
    {
        $role = $tenant->role;
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new \RuntimeException('the mall role is not usable');
        }
        if ($this->pdo->exec('SET ROLE ' . $role) === false) {
            throw new \RuntimeException('the mall role could not be used');
        }
        try {
            return $this->staffRow($tenant, $username);
        } finally {
            if ($this->pdo->exec('RESET ROLE') === false) {
                throw new \RuntimeException('the database role could not be reset');
            }
        }
    }

    /**
     * @return array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null}|null
     */
    private function staffRow(TenantRecord $tenant, string $username): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, display_name, password_hash, role, vendor_id::text AS vendor_id
             FROM ' . $this->schema($tenant->schema) . '.staff
             WHERE lower(username) = lower(?)'
        );
        $statement->execute([$username]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $id = $row['id'] ?? null;
        $displayName = $row['display_name'] ?? null;
        $hash = $row['password_hash'] ?? null;
        $role = $row['role'] ?? null;
        $vendorId = $row['vendor_id'] ?? null;
        if (!is_string($id) || !is_string($displayName) || !is_string($hash) || !is_string($role)) {
            return null;
        }

        return [
            'id' => $id,
            'display_name' => $displayName,
            'password_hash' => $hash,
            'role' => $role,
            'vendor_id' => is_string($vendorId) ? $vendorId : null,
        ];
    }

    /**
     * @param array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null} $row
     */
    private function passwordMatches(array $row, string $password): bool
    {
        return password_verify($password, $row['password_hash']);
    }

    /**
     * @param array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null} $row
     */
    private function knownRole(array $row): bool
    {
        return $row['role'] === 'cashier' || $row['role'] === 'vendor';
    }

    /**
     * @param array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null} $row
     */
    private function openSession(TenantRecord $tenant, string $username, array $row): void
    {
        $clear = $this->pdo->prepare(
            'DELETE FROM platform.owner_sign_in_failures WHERE tenant_id = ?::uuid AND username = ?'
        );
        $clear->execute([$tenant->id, $username]);
        $now = (string) time();
        $role = $row['role'] ?? null;
        $this->session->regenerateId(true);
        $this->session->set('owner_slug', $tenant->slug);
        $this->session->set('owner_name', is_string($row['display_name'] ?? null) ? $row['display_name'] : $username);
        $this->session->set('signed_in_at', $now);
        $this->session->set('last_seen_at', $now);
        $this->session->set('csrf', bin2hex(random_bytes(16)));
        $this->session->set('actor_role', is_string($role) ? $role : '');
        $this->session->set('actor_staff', is_string($row['id'] ?? null) ? $row['id'] : '');
        $vendor = $row['vendor_id'] ?? null;
        $this->session->set('actor_vendor', $role === 'vendor' && is_string($vendor) ? $vendor : '');
    }

    private function refuse(TenantRecord $tenant, string $username): void
    {
        $failed = $this->pdo->prepare(
            'INSERT INTO platform.owner_sign_in_failures (tenant_id, username) VALUES (?::uuid, ?)'
        );
        $failed->execute([$tenant->id, $username]);
        $this->logger->info('staff sign-in refused', ['slug' => $tenant->slug, 'username' => $username]);
    }

    private function schema(string $schema): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $schema) !== 1) {
            throw new \RuntimeException('the mall schema is not usable');
        }

        return $schema;
    }
}
