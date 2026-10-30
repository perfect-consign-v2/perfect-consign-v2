<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Bootstrap\LoginSession;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;

final class AdminEnterAs
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabaseRole $roles,
        private readonly LoginSession $sessions,
        private readonly AdminSession $admins,
        private readonly AdminAudit $audit,
        private readonly Statements $statements,
    ) {
    }

    /**
     * @param array{id: string, email: string, version: string} $admin
     */
    public function enter(array $admin, TenantRecord $tenant, string $role): string
    {
        if ($role !== 'owner' && $role !== 'cashier' && $role !== 'manager') {
            throw new \InvalidArgumentException('Choose owner, cashier, or manager.');
        }
        $preserved = $this->admins->snapshot();
        if ($role === 'owner') {
            return $this->asOwner($admin, $tenant, $preserved);
        }

        return $this->asStaff($admin, $tenant, $preserved, $role);
    }

    /**
     * @param array{id: string, email: string, version: string} $admin
     * @param array{id: string, email: string, version: string, signed_in_at?: string, last_seen_at?: string} $preserved
     */
    private function asOwner(array $admin, TenantRecord $tenant, array $preserved): string
    {
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, username, credentials_version
             FROM platform.owners
             WHERE tenant_id = ?::uuid'
        );
        $statement->execute([$tenant->id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            $this->audit->record(
                $admin['id'],
                $admin['email'],
                'tenant.enter_as_refused',
                'refused',
                $tenant->id,
                $tenant->slug,
                ['as_role' => 'owner', 'reason' => 'missing_owner'],
            );
            throw new \InvalidArgumentException('That store has no owner.');
        }
        $id = $row['id'] ?? null;
        $username = $row['username'] ?? null;
        $version = $row['credentials_version'] ?? null;
        if (is_int($version)) {
            $version = (string) $version;
        }
        if (!is_string($id) || !is_string($username) || !is_string($version)) {
            throw new \RuntimeException('the owner row is not usable');
        }
        $this->sessions->open($tenant->slug, $tenant->id, $username, 'owner', $id, $version, '', '');
        $this->admins->restore($preserved);
        $this->mallAudit($tenant, $username, 'owner', $id);
        $this->audit->record(
            $admin['id'],
            $admin['email'],
            'tenant.enter_as',
            'ok',
            $tenant->id,
            $tenant->slug,
            ['as_role' => 'owner', 'account_id' => $id],
        );

        return '/' . $tenant->slug . '/dashboard';
    }

    /**
     * @param array{id: string, email: string, version: string} $admin
     * @param array{id: string, email: string, version: string, signed_in_at?: string, last_seen_at?: string} $preserved
     */
    private function asStaff(array $admin, TenantRecord $tenant, array $preserved, string $role): string
    {
        if ($role !== 'cashier' && $role !== 'manager') {
            throw new \InvalidArgumentException('Choose owner, cashier, or manager.');
        }
        $row = $this->staffRow($tenant, $role);
        if ($row === null) {
            $this->audit->record(
                $admin['id'],
                $admin['email'],
                'tenant.enter_as_refused',
                'refused',
                $tenant->id,
                $tenant->slug,
                ['as_role' => $role, 'reason' => 'missing_' . $role],
            );
            throw new \InvalidArgumentException('That store has no ' . $role . '.');
        }
        $this->sessions->open(
            $tenant->slug,
            $tenant->id,
            $row['display_name'],
            $role,
            $row['id'],
            $row['version'],
            $row['id'],
            '',
        );
        $this->admins->restore($preserved);
        $this->mallAudit($tenant, $row['display_name'], $role, $row['id']);
        $this->audit->record(
            $admin['id'],
            $admin['email'],
            'tenant.enter_as',
            'ok',
            $tenant->id,
            $tenant->slug,
            ['as_role' => $role, 'account_id' => $row['id']],
        );

        return '/' . $tenant->slug . '/pos';
    }

    /**
     * @return array{id: string, username: string, display_name: string, version: string}|null
     */
    private function staffRow(TenantRecord $tenant, string $role): ?array
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $tenant->schema) !== 1) {
            throw new \RuntimeException('the mall schema is not usable');
        }
        $this->roles->assume($tenant->role);
        try {
            $statement = $this->pdo->prepare(
                'SELECT id::text AS id, username, display_name, credentials_version
                 FROM ' . $tenant->schema . '.staff
                 WHERE role = ?
                 ORDER BY lower(username)
                 LIMIT 1'
            );
            $statement->execute([$role]);
            $row = $statement->fetch();
        } finally {
            $this->roles->reset();
        }
        if (!is_array($row)) {
            return null;
        }
        $id = $row['id'] ?? null;
        $username = $row['username'] ?? null;
        $display = $row['display_name'] ?? null;
        $version = $row['credentials_version'] ?? null;
        if (is_int($version)) {
            $version = (string) $version;
        }
        if (!is_string($id) || !is_string($username) || !is_string($display) || !is_string($version)) {
            throw new \RuntimeException('the ' . $role . ' row is not usable');
        }

        return [
            'id' => $id,
            'username' => $username,
            'display_name' => $display,
            'version' => $version,
        ];
    }

    private function mallAudit(TenantRecord $tenant, string $actor, string $role, string $entityId): void
    {
        $this->roles->assume($tenant->role);
        try {
            if ($this->pdo->exec('SET search_path TO ' . $tenant->schema . ', pg_catalog, extensions') === false) {
                throw new \RuntimeException('the mall search path could not be set');
            }
            (new AuditLog($this->statements))->record($actor, $role, 'platform.enter', 'session', $entityId);
        } finally {
            if ($this->pdo->exec('RESET search_path') === false) {
                throw new \RuntimeException('the search path could not be reset');
            }
            $this->roles->reset();
        }
    }
}
