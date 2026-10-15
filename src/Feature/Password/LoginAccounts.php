<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;

final class LoginAccounts
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabaseRole $roles,
    ) {
    }

    public function find(TenantRecord $tenant, string $username): ?LoginAccount
    {
        $owner = $this->owner($tenant->id, $username);
        if ($owner !== null) {
            return $owner;
        }

        return $this->staff($tenant, $username);
    }

    public function shown(TenantRecord $tenant, string $role, string $accountId): string
    {
        if ($role === 'owner') {
            $this->roles->reset();
            try {
                return $this->ownerEmail($tenant->id, $accountId);
            } finally {
                $this->roles->assume($tenant->role);
            }
        }

        return $this->staffEmail($tenant->schema, $accountId);
    }

    private function owner(string $tenantId, string $username): ?LoginAccount
    {
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, email
             FROM platform.owners
             WHERE tenant_id = ?::uuid AND username = ?'
        );
        $statement->execute([$tenantId, $username]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $id = $row['id'] ?? null;
        if (!is_string($id)) {
            return null;
        }
        $email = $row['email'] ?? null;

        return new LoginAccount('owner', $id, $username, is_string($email) ? $email : '');
    }

    private function staff(TenantRecord $tenant, string $username): ?LoginAccount
    {
        $this->roles->assume($tenant->role);
        try {
            $statement = $this->pdo->prepare(
                'SELECT id::text AS id, email, role
                 FROM ' . $this->table($tenant->schema) . '
                 WHERE lower(username) = lower(?)'
            );
            $statement->execute([$username]);
            $row = $statement->fetch();
        } finally {
            $this->roles->reset();
        }
        if (!is_array($row)) {
            return null;
        }
        $id = $row['id'] ?? null;
        $role = $row['role'] ?? null;
        if (!is_string($id) || ($role !== 'cashier' && $role !== 'vendor')) {
            return null;
        }
        $email = $row['email'] ?? null;

        return new LoginAccount('staff', $id, $username, is_string($email) ? $email : '');
    }

    private function ownerEmail(string $tenantId, string $accountId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT email FROM platform.owners WHERE id = ?::uuid AND tenant_id = ?::uuid'
        );
        $statement->execute([$accountId, $tenantId]);

        return $this->email($statement->fetchColumn());
    }

    private function staffEmail(string $schema, string $accountId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT email FROM ' . $this->table($schema) . ' WHERE id = ?::uuid'
        );
        $statement->execute([$accountId]);

        return $this->email($statement->fetchColumn());
    }

    private function email(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function table(string $schema): string
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new \RuntimeException('the mall schema is not usable');
        }

        return $schema . '.staff';
    }
}
