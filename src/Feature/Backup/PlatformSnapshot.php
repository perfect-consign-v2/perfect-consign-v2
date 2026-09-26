<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

use PDO;

final class PlatformSnapshot
{
    /**
     * @return array{id: string, slug: string, schema: string, display_name: string, created_at: string}
     */
    public function tenant(PDO $pdo, string $slug): array
    {
        $statement = $pdo->prepare(
            'SELECT id, slug, schema_name, display_name, created_at FROM platform.tenants WHERE slug = ?'
        );
        $statement->execute([$slug]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $tenant = $this->tenantRow($row, $slug);
        if ($tenant === null) {
            throw new BackupException('That mall has no backup.');
        }

        return $tenant;
    }

    /**
     * @return array{id: string, username: string, password_hash: string, created_at: string}|null
     */
    public function owner(PDO $pdo, string $tenantId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id, username, password_hash, created_at FROM platform.owners WHERE tenant_id = ?::uuid'
        );
        $statement->execute([$tenantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->ownerRow($row);
    }

    /**
     * @param array{id: string, slug: string, schema: string, display_name: string, created_at: string} $tenant
     * @param array{id: string, username: string, password_hash: string, created_at: string}|null $owner
     */
    public function platformSql(PDO $pdo, array $tenant, ?array $owner): string
    {
        $sql = 'INSERT INTO platform.tenants (id, slug, schema_name, display_name, created_at) VALUES ('
            . $this->quote($pdo, $tenant['id']) . ', '
            . $this->quote($pdo, $tenant['slug']) . ', '
            . $this->quote($pdo, $tenant['schema']) . ', '
            . $this->quote($pdo, $tenant['display_name']) . ', '
            . $this->quote($pdo, $tenant['created_at'])
            . ");\n";
        if ($owner === null) {
            return $sql;
        }

        return $sql . $this->ownerSql($pdo, $tenant['id'], $owner);
    }

    /**
     * @param array{id: string, slug: string, schema: string, display_name: string, created_at: string} $tenant
     * @param array{id: string, username: string, password_hash: string, created_at: string}|null $owner
     */
    public function manifest(
        string $backupId,
        string $takenAt,
        array $tenant,
        ?array $owner,
        string $version,
        string $schemaHash,
    ): string {
        $text = 'backup_id=' . $backupId . "\n"
            . 'taken_at=' . $takenAt . "\n"
            . 'tenant_id=' . $tenant['id'] . "\n"
            . 'slug=' . $tenant['slug'] . "\n"
            . 'schema=' . $tenant['schema'] . "\n"
            . 'postgres=' . $version . "\n"
            . 'schema_sha256=' . $schemaHash . "\n";
        if ($owner === null) {
            return $text;
        }

        return $text
            . 'owner_id=' . $owner['id'] . "\n"
            . 'owner_username=' . $owner['username'] . "\n"
            . 'owner_password_hash=' . $owner['password_hash'] . "\n";
    }

    /**
     * @param array{id: string, username: string, password_hash: string, created_at: string} $owner
     */
    private function ownerSql(PDO $pdo, string $tenantId, array $owner): string
    {
        return 'INSERT INTO platform.owners (id, tenant_id, username, password_hash, created_at) VALUES ('
            . $this->quote($pdo, $owner['id']) . ', '
            . $this->quote($pdo, $tenantId) . ', '
            . $this->quote($pdo, $owner['username']) . ', '
            . $this->quote($pdo, $owner['password_hash']) . ', '
            . $this->quote($pdo, $owner['created_at'])
            . ");\n";
    }

    /**
     * @return array{id: string, slug: string, schema: string, display_name: string, created_at: string}|null
     */
    private function tenantRow(mixed $row, string $slug): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $id = $row['id'] ?? null;
        $schema = $row['schema_name'] ?? null;
        $displayName = $row['display_name'] ?? null;
        $createdAt = $row['created_at'] ?? null;
        if (!is_string($id) || !is_string($schema) || !is_string($displayName) || !is_string($createdAt)) {
            return null;
        }
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            return null;
        }

        return [
            'id' => $id,
            'slug' => $slug,
            'schema' => $schema,
            'display_name' => $displayName,
            'created_at' => $createdAt,
        ];
    }

    /**
     * @return array{id: string, username: string, password_hash: string, created_at: string}
     */
    private function ownerRow(mixed $row): array
    {
        if (!is_array($row)) {
            throw new BackupException('The backup could not be taken.');
        }
        $id = $row['id'] ?? null;
        $username = $row['username'] ?? null;
        $passwordHash = $row['password_hash'] ?? null;
        $createdAt = $row['created_at'] ?? null;
        if (!is_string($id) || !is_string($username) || !is_string($passwordHash) || !is_string($createdAt)) {
            throw new BackupException('The backup could not be taken.');
        }
        if (str_contains($passwordHash, "\n") || str_contains($username, "\n")) {
            throw new BackupException('The backup could not be taken.');
        }

        return [
            'id' => $id,
            'username' => $username,
            'password_hash' => $passwordHash,
            'created_at' => $createdAt,
        ];
    }

    private function quote(PDO $pdo, string $value): string
    {
        $quoted = $pdo->quote($value);
        if ($quoted === false) {
            throw new BackupException('The backup could not be taken.');
        }

        return $quoted;
    }
}
