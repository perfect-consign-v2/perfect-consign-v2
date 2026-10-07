<?php

declare(strict_types=1);

namespace PerfectConsign\Schema;

use PDO;
use RuntimeException;

final class SchemaPresence
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{
     *     tables: list<string>,
     *     routines: list<string>,
     *     columns: list<array{0: string, 1: string}>,
     *     triggers: list<string>,
     *     constraints: list<string>
     * } $objects
     * @return array{present: int, missing: ?string}
     */
    public function coverage(string $schema, array $objects): array
    {
        $this->schema($schema);
        $present = 0;
        $missing = null;
        foreach ($objects['tables'] as $name) {
            $this->mark($this->regclass($schema, $name), $name, $present, $missing);
        }
        foreach ($objects['routines'] as $name) {
            $this->mark($this->routine($schema, $name), $name, $present, $missing);
        }
        foreach ($objects['columns'] as $column) {
            $this->mark($this->column($schema, $column[0], $column[1]), $column[1], $present, $missing);
        }
        foreach ($objects['triggers'] as $name) {
            $this->mark($this->triggerName($schema, $name), $name, $present, $missing);
        }
        foreach ($objects['constraints'] as $name) {
            $this->mark($this->constraint($schema, $name), $name, $present, $missing);
        }
        if ($present === 0 && $missing === null) {
            $missing = 'objects';
        }

        return ['present' => $present, 'missing' => $missing];
    }

    private function mark(bool $found, string $name, int &$present, ?string &$missing): void
    {
        if ($found) {
            $present++;

            return;
        }
        if ($missing === null) {
            $missing = $name;
        }
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

    private function column(string $schema, string $table, string $name): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ? AND column_name = ?'
        );
        $statement->execute([$schema, $this->identifier($table), $this->identifier($name)]);

        return $statement->fetchColumn() !== false;
    }

    private function triggerName(string $schema, string $name): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM pg_trigger
             JOIN pg_class ON pg_class.oid = pg_trigger.tgrelid
             JOIN pg_namespace ON pg_namespace.oid = pg_class.relnamespace
             WHERE pg_namespace.nspname = ? AND pg_trigger.tgname = ? AND NOT pg_trigger.tgisinternal'
        );
        $statement->execute([$schema, $this->identifier($name)]);

        return $statement->fetchColumn() !== false;
    }

    private function constraint(string $schema, string $name): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM pg_constraint
             JOIN pg_namespace ON pg_namespace.oid = pg_constraint.connamespace
             WHERE pg_namespace.nspname = ? AND pg_constraint.conname = ?'
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

    private function schema(string $schema): void
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new RuntimeException('schema name is not a tenant schema');
        }
    }
}
