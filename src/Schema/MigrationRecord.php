<?php

declare(strict_types=1);

namespace PerfectConsign\Schema;

use PDO;
use RuntimeException;

final class MigrationRecord
{
    private readonly MigrationLock $lock;

    private readonly SchemaBaseline $baseline;

    public function __construct(
        private readonly PDO $pdo,
        private readonly SchemaCatalog $catalog,
    ) {
        $this->lock = new MigrationLock($pdo);
        $this->baseline = new SchemaBaseline($pdo);
    }

    public function holdMigration(): void
    {
        $this->lock->hold();
    }

    public function releaseMigration(): void
    {
        $this->lock->release();
    }

    public function requireBaseline(string $slug, string $schema): void
    {
        if (!$this->baseline->window($schema)) {
            throw new TenantBaselineMismatch(
                'tenant ' . $slug . ' does not match the closed payout window and was not stamped'
            );
        }
        $objects = $this->catalog->baselineObjects();
        $missing = $this->baseline->absent($schema, $objects['tables'], $objects['routines']);
        if ($missing !== null) {
            throw new TenantBaselineMismatch('tenant ' . $slug . ' is missing ' . $missing . ' and was not stamped');
        }
    }

    public function ready(string $schema): bool
    {
        $query = $this->pdo->query('SELECT to_regclass(' . $this->pdo->quote($this->relation($schema)) . ')');
        if ($query === false) {
            return false;
        }
        $found = $query->fetchColumn();

        return is_string($found) && $found !== '';
    }

    public function verify(string $schema): void
    {
        if (!$this->ready($schema)) {
            return;
        }
        foreach ($this->applied($schema) as $file => $checksum) {
            if ($this->catalog->checksum($file) !== $checksum) {
                throw new MigrationFileChanged($file . ' changed after it was applied');
            }
        }
    }

    /**
     * @param list<string> $files
     */
    public function stamp(string $schema, array $files): void
    {
        $applied = $this->applied($schema);
        foreach ($files as $file) {
            $checksum = $this->catalog->checksum($file);
            $existing = $applied[$file] ?? null;
            if ($existing === $checksum) {
                continue;
            }
            if ($existing !== null) {
                throw new MigrationFileChanged($file . ' changed after it was applied');
            }
            $this->remember($schema, $file, $checksum);
        }
    }

    /**
     * @return array<string, string>
     */
    public function applied(string $schema): array
    {
        $query = $this->pdo->query(
            'SELECT filename, checksum FROM ' . $this->relation($schema)
        );
        if ($query === false) {
            throw new RuntimeException('could not read schema migrations');
        }
        $rows = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('could not read schema migrations');
            }
            $file = $row['filename'] ?? null;
            $checksum = $row['checksum'] ?? null;
            if (!is_string($file) || !is_string($checksum)) {
                throw new RuntimeException('could not read schema migrations');
            }
            $rows[$file] = $checksum;
        }

        return $rows;
    }

    private function remember(string $schema, string $file, string $checksum): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ' . $this->relation($schema) . ' (filename, checksum) VALUES (?, ?)'
        );
        $statement->execute([$file, $checksum]);
    }

    private function relation(string $schema): string
    {
        if ($schema === 'platform') {
            return 'platform.schema_migrations';
        }
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new RuntimeException('schema name is not a tenant schema');
        }

        return $schema . '.schema_migrations';
    }
}
