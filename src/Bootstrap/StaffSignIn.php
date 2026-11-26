<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectConsign\Feature\Vendor\VendorPortalAccess;
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

    /**
     * Checks a staff password. The row comes back only when it is right.
     *
     * @return array{0: SignInCheck, 1: array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null, version: string, can_sign_in: bool}|null}
     */
    public function check(TenantRecord $tenant, string $username, string $password): array
    {
        $row = $this->lookup($tenant, $username);
        if ($row === null) {
            return [SignInCheck::NoAccount, null];
        }
        if (
            !$row['can_sign_in']
            || !$this->passwordMatches($row, $password)
            || !$this->knownRole($row)
            || !$this->vendorActive($tenant, $row)
        ) {
            $this->logger->info('staff sign-in refused', ['slug' => $tenant->slug, 'username' => $username]);

            return [SignInCheck::Wrong, null];
        }

        return [SignInCheck::Right, $row];
    }

    /**
     * Opens the session for a staff member whose password was right.
     *
     * @param array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null, version: string, can_sign_in: bool} $row
     */
    public function open(TenantRecord $tenant, string $username, array $row): void
    {
        if ($row['role'] === 'vendor' && !$this->portalOpen($tenant)) {
            $this->logger->info('vendor portal closed', ['slug' => $tenant->slug]);
            throw new PortalClosed('The vendor portal is off.');
        }
        $this->openSession($tenant, $username, $row);
    }

    /**
     * @return array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null, version: string, can_sign_in: bool}|null
     */
    private function lookup(TenantRecord $tenant, string $username): ?array
    {
        return $this->asTenantRole($tenant, fn (): ?array => $this->staffRow($tenant, $username));
    }

    /**
     * @return array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null, version: string, can_sign_in: bool}|null
     */
    private function staffRow(TenantRecord $tenant, string $username): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, display_name, password_hash, role, vendor_id::text AS vendor_id,
                    credentials_version, can_sign_in
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
        $canSignIn = $this->flag($row['can_sign_in'] ?? null);
        if (
            !is_string($id)
            || !is_string($displayName)
            || !is_string($hash)
            || !is_string($role)
            || $version === null
            || $canSignIn === null
        ) {
            return null;
        }

        return [
            'id' => $id,
            'display_name' => $displayName,
            'password_hash' => $hash,
            'role' => $role,
            'vendor_id' => is_string($vendorId) ? $vendorId : null,
            'version' => $version,
            'can_sign_in' => $canSignIn,
        ];
    }

    private function flag(mixed $value): ?bool
    {
        return match (true) {
            is_bool($value) => $value,
            $value === 't', $value === 'true', $value === 1, $value === '1' => true,
            $value === 'f', $value === 'false', $value === 0, $value === '0' => false,
            default => null,
        };
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
     * @param array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null, version: string, can_sign_in: bool} $row
     */
    private function passwordMatches(array $row, string $password): bool
    {
        return password_verify($password, $row['password_hash']);
    }

    /**
     * @param array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null, version: string, can_sign_in: bool} $row
     */
    private function knownRole(array $row): bool
    {
        return $row['role'] === 'cashier'
            || $row['role'] === 'manager'
            || $row['role'] === 'vendor'
            || $row['role'] === 'books';
    }

    /**
     * @param array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null, version: string, can_sign_in: bool} $row
     */
    private function vendorActive(TenantRecord $tenant, array $row): bool
    {
        if ($row['role'] !== 'vendor') {
            return true;
        }
        $vendorId = $row['vendor_id'];
        if (!is_string($vendorId) || preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1) {
            return false;
        }

        return $this->vendorMarkedActive($tenant, $vendorId);
    }

    private function portalOpen(TenantRecord $tenant): bool
    {
        return $this->asTenantRole(
            $tenant,
            fn (): bool => VendorPortalAccess::portalEnabledIn($this->pdo, $tenant->schema),
        );
    }

    private function vendorMarkedActive(TenantRecord $tenant, string $vendorId): bool
    {
        return $this->asTenantRole($tenant, function () use ($tenant, $vendorId): bool {
            $statement = $this->pdo->prepare(
                'SELECT active FROM ' . $this->schema($tenant->schema) . '.vendors WHERE id = ?::uuid'
            );
            $statement->execute([$vendorId]);

            return $this->flag($statement->fetchColumn()) === true;
        });
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function asTenantRole(TenantRecord $tenant, callable $work): mixed
    {
        $role = $tenant->role;
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new \RuntimeException('the mall role is not usable');
        }
        if ($this->pdo->exec('SET ROLE ' . $role) === false) {
            throw new \RuntimeException('the mall role could not be used');
        }
        try {
            return $work();
        } finally {
            if ($this->pdo->exec('RESET ROLE') === false) {
                throw new \RuntimeException('the database role could not be reset');
            }
        }
    }

    /**
     * @param array{id: string, display_name: string, password_hash: string, role: string, vendor_id: string|null, version: string, can_sign_in: bool} $row
     */
    private function openSession(TenantRecord $tenant, string $username, array $row): void
    {
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
            $username,
        );
    }

    private function schema(string $schema): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $schema) !== 1) {
            throw new \RuntimeException('the mall schema is not usable');
        }

        return $schema;
    }
}
