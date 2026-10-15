<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;

final class OwnPassword
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabaseRole $roles,
        private readonly PasswordChoice $passwords,
        private readonly RecoveryAddress $addresses,
    ) {
    }

    public function changeOwner(
        TenantRecord $tenant,
        string $accountId,
        string $current,
        string $next,
        string $confirm,
    ): string {
        $hash = $this->passwords->changed($current, $this->ownerHash($tenant, $accountId), $next, $confirm);
        $this->roles->reset();
        try {
            return $this->writeOwner($tenant->id, $accountId, $hash);
        } finally {
            $this->roles->assume($tenant->role);
        }
    }

    public function changeStaff(TenantRecord $tenant, string $accountId, string $current, string $next, string $confirm): string
    {
        $hash = $this->passwords->changed($current, $this->staffHash($tenant->schema, $accountId), $next, $confirm);

        return $this->writeStaff($tenant->schema, $accountId, $hash);
    }

    public function emailOwner(TenantRecord $tenant, string $accountId, string $current, string $email): void
    {
        $email = $this->addresses->parse($email);
        $this->passwords->current($current, $this->ownerHash($tenant, $accountId));
        $this->roles->reset();
        try {
            $this->storeOwnerEmail($tenant->id, $accountId, $email);
        } finally {
            $this->roles->assume($tenant->role);
        }
    }

    public function emailStaff(TenantRecord $tenant, string $accountId, string $current, string $email): void
    {
        $email = $this->addresses->parse($email);
        $this->passwords->current($current, $this->staffHash($tenant->schema, $accountId));
        $this->storeStaffEmail($tenant->schema, $accountId, $email);
    }

    private function writeOwner(string $tenantId, string $accountId, string $hash): string
    {
        $statement = $this->pdo->prepare(
            'UPDATE platform.owners
             SET password_hash = ?, credentials_version = credentials_version + 1
             WHERE id = ?::uuid AND tenant_id = ?::uuid
             RETURNING credentials_version'
        );
        $statement->execute([$hash, $accountId, $tenantId]);

        return $this->version($statement->fetchColumn());
    }

    private function writeStaff(string $schema, string $accountId, string $hash): string
    {
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->table($schema) . '
             SET password_hash = ?, credentials_version = credentials_version + 1
             WHERE id = ?::uuid
             RETURNING credentials_version'
        );
        $statement->execute([$hash, $accountId]);

        return $this->version($statement->fetchColumn());
    }

    private function storeOwnerEmail(string $tenantId, string $accountId, string $email): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE platform.owners SET email = ? WHERE id = ?::uuid AND tenant_id = ?::uuid'
        );
        $statement->execute([$email, $accountId, $tenantId]);
        if ($statement->rowCount() !== 1) {
            throw new \InvalidArgumentException('That sign-in was not found.');
        }
    }

    private function storeStaffEmail(string $schema, string $accountId, string $email): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->table($schema) . ' SET email = ? WHERE id = ?::uuid'
        );
        $statement->execute([$email, $accountId]);
        if ($statement->rowCount() !== 1) {
            throw new \InvalidArgumentException('That sign-in was not found.');
        }
    }

    private function ownerHash(TenantRecord $tenant, string $accountId): string
    {
        $this->roles->reset();
        try {
            $statement = $this->pdo->prepare(
                'SELECT password_hash FROM platform.owners WHERE id = ?::uuid AND tenant_id = ?::uuid'
            );
            $statement->execute([$accountId, $tenant->id]);

            return $this->hash($statement->fetchColumn());
        } finally {
            $this->roles->assume($tenant->role);
        }
    }

    private function staffHash(string $schema, string $accountId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT password_hash FROM ' . $this->table($schema) . ' WHERE id = ?::uuid'
        );
        $statement->execute([$accountId]);

        return $this->hash($statement->fetchColumn());
    }

    private function hash(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException('That sign-in was not found.');
        }

        return $value;
    }

    private function version(mixed $value): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || ctype_digit($value) !== true) {
            throw new \InvalidArgumentException('That sign-in was not found.');
        }

        return $value;
    }

    private function table(string $schema): string
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new \RuntimeException('the mall schema is not usable');
        }

        return $schema . '.staff';
    }
}
