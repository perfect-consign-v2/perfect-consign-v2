<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;
use Psr\Log\LoggerInterface;

final class StaffSignIn
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger,
        private readonly LoginSession $sessions,
    ) {
    }

    public function attempt(TenantRecord $tenant, string $username, string $password, string $address): bool
    {
        $row = $this->lookup($tenant, $username);
        if ($row === null || !$this->passwordMatches($row, $password) || !$this->knownRole($row)) {
            $this->refuse($tenant, $username, $address);

            return false;
        }
        $this->openSession($tenant, $username, $row);

        return true;
    }

    /**
     * @return array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null, version: string}|null
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
     * @return array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null, version: string}|null
     */
    private function staffRow(TenantRecord $tenant, string $username): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, display_name, password_hash, role, vendor_id::text AS vendor_id, credentials_version
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
        $version = $this->version($row['credentials_version'] ?? null);
        if (!is_string($id) || !is_string($displayName) || !is_string($hash) || !is_string($role) || $version === null) {
            return null;
        }

        return [
            'id' => $id,
            'display_name' => $displayName,
            'password_hash' => $hash,
            'role' => $role,
            'vendor_id' => is_string($vendorId) ? $vendorId : null,
            'version' => $version,
        ];
    }

    private function version(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || ctype_digit($value) !== true) {
            return null;
        }

        return $value;
    }

    /**
     * @param array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null, version: string} $row
     */
    private function passwordMatches(array $row, string $password): bool
    {
        return password_verify($password, $row['password_hash']);
    }

    /**
     * @param array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null, version: string} $row
     */
    private function knownRole(array $row): bool
    {
        return $row['role'] === 'cashier' || $row['role'] === 'vendor' || $row['role'] === 'books';
    }

    /**
     * @param array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null, version: string} $row
     */
    private function openSession(TenantRecord $tenant, string $username, array $row): void
    {
        $clear = $this->pdo->prepare(
            'DELETE FROM platform.owner_sign_in_failures WHERE tenant_id = ?::uuid AND username = ?'
        );
        $clear->execute([$tenant->id, $username]);
        $role = $row['role'];
        $vendor = $row['vendor_id'];
        $this->sessions->open(
            $tenant->slug,
            $tenant->id,
            $row['display_name'],
            $role,
            $row['id'],
            $row['version'],
            $row['id'],
            $role === 'vendor' && is_string($vendor) ? $vendor : '',
        );
    }

    private function refuse(TenantRecord $tenant, string $username, string $address): void
    {
        $failed = $this->pdo->prepare(
            'INSERT INTO platform.owner_sign_in_failures (tenant_id, username, client_address) VALUES (?::uuid, ?, ?)'
        );
        $failed->execute([$tenant->id, $username, $address]);
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
