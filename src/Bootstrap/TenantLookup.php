<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;

final class TenantLookup
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(string $slug): ?TenantRecord
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $slug) !== 1) {
            return null;
        }
        if ($this->pdo->exec('RESET ROLE') === false) {
            throw new \RuntimeException('the database role could not be reset');
        }
        $statement = $this->pdo->prepare(
            'SELECT id, slug, schema_name, display_name FROM platform.tenants WHERE slug = ?'
        );
        $statement->execute([$slug]);
        $row = $statement->fetch();
        if (!is_array($row) || !is_string($row['schema_name'] ?? null)) {
            return null;
        }
        $schema = $row['schema_name'];
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            return null;
        }

        return $this->record($row, $schema);
    }

    /**
     * @param array<mixed> $row
     */
    private function record(array $row, string $schema): ?TenantRecord
    {
        $id = $row['id'] ?? null;
        $rowSlug = $row['slug'] ?? null;
        $displayName = $row['display_name'] ?? null;
        if (!is_string($id) || !is_string($rowSlug) || !is_string($displayName)) {
            return null;
        }
        $role = TenantRecord::roleFor($rowSlug, $schema);

        return new TenantRecord($id, $rowSlug, $schema, $role, $displayName);
    }
}
