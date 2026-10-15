<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;

final class CredentialStamp
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Session $session,
        private readonly DatabaseRole $roles,
    ) {
    }

    public function matches(TenantRecord $tenant): bool
    {
        $stored = $this->session->get('credentials_version');
        $account = $this->session->get('actor_account');
        $role = $this->session->get('actor_role');
        if (!is_string($stored) || ctype_digit($stored) !== true || !is_string($account) || !is_string($role)) {
            return false;
        }
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $account) !== 1) {
            return false;
        }
        $live = $role === 'owner' ? $this->owner($tenant->id, $account) : $this->staff($tenant, $account, $role);

        return $live !== null && $live === $stored;
    }

    private function owner(string $tenantId, string $account): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT credentials_version
             FROM platform.owners
             WHERE id = ?::uuid AND tenant_id = ?::uuid'
        );
        $statement->execute([$account, $tenantId]);

        return $this->version($statement->fetchColumn());
    }

    private function staff(TenantRecord $tenant, string $account, string $role): ?string
    {
        if ($role !== 'cashier' && $role !== 'vendor') {
            return null;
        }
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $tenant->schema) !== 1) {
            return null;
        }
        $this->roles->assume($tenant->role);
        try {
            $statement = $this->pdo->prepare(
                'SELECT credentials_version FROM ' . $tenant->schema . '.staff WHERE id = ?::uuid AND role = ?'
            );
            $statement->execute([$account, $role]);
            $version = $statement->fetchColumn();
        } finally {
            $this->roles->reset();
        }

        return $this->version($version);
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
}
