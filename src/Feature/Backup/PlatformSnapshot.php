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
     * @return list<array{id: string, username: string, password_hash: string, created_at: string}>
     */
    public function owners(PDO $pdo, string $tenantId): array
    {
        $statement = $pdo->prepare(
            'SELECT id, username, password_hash, created_at
             FROM platform.owners
             WHERE tenant_id = ?::uuid
             ORDER BY lower(username)'
        );
        $statement->execute([$tenantId]);
        $owners = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $owners[] = $this->ownerRow($row);
        }

        return $owners;
    }

    /**
     * @param array{id: string, slug: string, schema: string, display_name: string, created_at: string} $tenant
     * @param list<array{id: string, username: string, password_hash: string, created_at: string}> $owners
     */
    public function platformSql(PDO $pdo, array $tenant, array $owners): string
    {
        $sql = 'INSERT INTO platform.tenants (id, slug, schema_name, display_name, created_at) VALUES ('
            . $this->quote($pdo, $tenant['id']) . ', '
            . $this->quote($pdo, $tenant['slug']) . ', '
            . $this->quote($pdo, $tenant['schema']) . ', '
            . $this->quote($pdo, $tenant['display_name']) . ', '
            . $this->quote($pdo, $tenant['created_at'])
            . ");\n";
        foreach ($owners as $owner) {
            $sql .= $this->ownerSql($pdo, $tenant['id'], $owner);
        }

        return $sql;
    }

    /**
     * @param array{id: string, slug: string, schema: string, display_name: string, created_at: string} $tenant
     * @param list<array{id: string, username: string, password_hash: string, created_at: string}> $owners
     */
    public function manifest(
        string $backupId,
        string $takenAt,
        array $tenant,
        array $owners,
        string $version,
        string $schemaHash,
    ): string {
        $text = 'backup_id=' . $backupId . "\n"
            . 'taken_at=' . $takenAt . "\n"
            . 'tenant_id=' . $tenant['id'] . "\n"
            . 'slug=' . $tenant['slug'] . "\n"
            . 'schema=' . $tenant['schema'] . "\n"
            . 'postgres=' . $version . "\n"
            . 'schema_sha256=' . $schemaHash . "\n"
            . 'owner_count=' . (string) count($owners) . "\n";
        foreach ($owners as $index => $owner) {
            $prefix = 'owner.' . (string) $index . '.';
            $text .= $prefix . 'id=' . $owner['id'] . "\n"
                . $prefix . 'username=' . $owner['username'] . "\n"
                . $prefix . 'password_hash=' . $owner['password_hash'] . "\n";
        }

        return $text;
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
