<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;

final class AdminTenantList
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabaseRole $roles,
    ) {
    }

    /**
     * @return list<array{id: string, slug: string, displayName: string, mode: string, modeLabel: string, owners: list<array{id: string, username: string}>}>
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT id::text AS id, slug, schema_name, display_name
             FROM platform.tenants
             ORDER BY slug'
        );
        if ($statement === false) {
            throw new \RuntimeException('the tenant list could not be read');
        }
        $rows = [];
        while ($row = $statement->fetch()) {
            if (!is_array($row)) {
                continue;
            }
            $id = $row['id'] ?? null;
            $slug = $row['slug'] ?? null;
            $schema = $row['schema_name'] ?? null;
            $name = $row['display_name'] ?? null;
            if (!is_string($id) || !is_string($slug) || !is_string($schema) || !is_string($name)) {
                continue;
            }
            $mode = $this->operatingMode($slug, $schema);
            $rows[] = [
                'id' => $id,
                'slug' => $slug,
                'displayName' => $name,
                'mode' => $mode,
                'modeLabel' => $this->label($mode),
                'owners' => $this->owners($id),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{id: string, username: string}>
     */
    private function owners(string $tenantId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, username
             FROM platform.owners
             WHERE tenant_id = ?::uuid
             ORDER BY lower(username)'
        );
        $statement->execute([$tenantId]);
        $owners = [];
        while ($row = $statement->fetch()) {
            if (!is_array($row)) {
                continue;
            }
            $id = $row['id'] ?? null;
            $username = $row['username'] ?? null;
            if (!is_string($id) || !is_string($username)) {
                continue;
            }
            $owners[] = ['id' => $id, 'username' => $username];
        }

        return $owners;
    }

    public function find(string $slug): ?TenantRecord
    {
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, slug, schema_name, display_name
             FROM platform.tenants
             WHERE slug = ?'
        );
        $statement->execute([$slug]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $id = $row['id'] ?? null;
        $foundSlug = $row['slug'] ?? null;
        $schema = $row['schema_name'] ?? null;
        $name = $row['display_name'] ?? null;
        if (!is_string($id) || !is_string($foundSlug) || !is_string($schema) || !is_string($name)) {
            return null;
        }

        return new TenantRecord(
            $id,
            $foundSlug,
            $schema,
            TenantRecord::roleFor($foundSlug, $schema),
            $name,
        );
    }

    private function operatingMode(string $slug, string $schema): string
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new \RuntimeException('the mall schema is not usable');
        }
        $role = TenantRecord::roleFor($slug, $schema);
        $this->roles->assume($role);
        try {
            $statement = $this->pdo->query('SELECT operating_mode FROM ' . $schema . '.mall');
            if ($statement === false) {
                throw new \RuntimeException('the mall mode could not be read');
            }
            $mode = $statement->fetchColumn();
        } finally {
            $this->roles->reset();
        }
        if (!is_string($mode) || !in_array($mode, ['consignor', 'booth_mall', 'both'], true)) {
            return 'both';
        }

        return $mode;
    }

    private function label(string $mode): string
    {
        return match ($mode) {
            'consignor' => 'Consignment',
            'booth_mall' => 'Booth mall',
            default => 'Consignment and booths',
        };
    }
}
