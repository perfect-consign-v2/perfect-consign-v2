<?php

declare(strict_types=1);

namespace PerfectConsign\Schema;

use PDO;
use PDOException;
use RuntimeException;

final class MigrationRecord
{
    private const LOCK = 814202615;

    public function __construct(
        private readonly PDO $pdo,
        private readonly SchemaCatalog $catalog,
    ) {
    }

    public function holdMigration(): void
    {
        $query = $this->pdo->query('SELECT pg_try_advisory_lock(' . self::LOCK . ')');
        $value = $query === false ? false : $query->fetchColumn();
        if ($value !== true && $value !== 't' && $value !== 1 && $value !== '1') {
            throw new RuntimeException('a migration is already running');
        }
    }

    public function releaseMigration(): void
    {
        $this->clearAbortedTransaction();
        $query = $this->pdo->query('SELECT pg_advisory_unlock(' . self::LOCK . ')');
        if ($query === false) {
            throw new RuntimeException('the migration lock could not be released');
        }
    }

    public function requireBaseline(string $slug, string $schema): void
    {
        if (!$this->window($schema)) {
            throw new TenantBaselineMismatch(
                'tenant ' . $slug . ' does not match the closed payout window and was not stamped'
            );
        }
        $objects = $this->catalog->baselineObjects();
        $missing = $this->absent($schema, $objects['tables'], $objects['routines']);
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

    private function clearAbortedTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            return;
        }
        try {
            $probe = $this->pdo->query('SELECT 1');
            if ($probe === false) {
                $this->pdo->rollBack();
            }
        } catch (PDOException) {
            $this->pdo->rollBack();
        }
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

    /**
     * @param list<string> $tables
     * @param list<string> $routines
     */
    private function absent(string $schema, array $tables, array $routines): ?string
    {
        foreach ($tables as $table) {
            if (!$this->regclass($schema, $table)) {
                return $table;
            }
        }
        foreach ($routines as $routine) {
            if (!$this->routine($schema, $routine)) {
                return $routine;
            }
        }

        return null;
    }

    private function regclass(string $schema, string $name): bool
    {
        $query = $this->pdo->query(
            'SELECT to_regclass(' . $this->pdo->quote($schema . '.' . $this->identifier($name)) . ')'
        );
        if ($query === false) {
            return false;
        }
        $found = $query->fetchColumn();

        return is_string($found) && $found !== '';
    }

    private function routine(string $schema, string $name): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM pg_proc
             JOIN pg_namespace ON pg_namespace.oid = pg_proc.pronamespace
             WHERE pg_namespace.nspname = ? AND pg_proc.proname = ?'
        );
        $statement->execute([$schema, $this->identifier($name)]);

        return $statement->fetchColumn() !== false;
    }

    private function identifier(string $name): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
            throw new RuntimeException('schema object name is invalid');
        }

        return $name;
    }

    private function window(string $schema): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT pg_trigger.tgconstraint = 0 AND NOT pg_trigger.tgdeferrable
             FROM pg_trigger
             JOIN pg_class ON pg_class.oid = pg_trigger.tgrelid
             JOIN pg_namespace ON pg_namespace.oid = pg_class.relnamespace
             WHERE pg_namespace.nspname = ?
               AND pg_class.relname = \'journals\'
               AND pg_trigger.tgname = \'journals_closed_payout_window\'
               AND NOT pg_trigger.tgisinternal'
        );
        $statement->execute([$schema]);
        $value = $statement->fetchColumn();

        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }
}
