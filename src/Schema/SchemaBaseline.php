<?php

declare(strict_types=1);

namespace PerfectConsign\Schema;

use PDO;
use RuntimeException;

final class SchemaBaseline
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<string> $tables
     * @param list<string> $routines
     */
    public function absent(string $schema, array $tables, array $routines): ?string
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

    public function window(string $schema): bool
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
}
