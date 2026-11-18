<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Database;

final class CatalogLinks
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * Ids that a foreign key from the catalog still points at.
     *
     * @return array<string, true>
     */
    public function referenced(string $table): array
    {
        $this->requireTable($table);
        $held = [];
        foreach ($this->links($table) as $link) {
            $held += $this->ids($link['relation'], $link['column']);
        }

        return $held;
    }

    /**
     * Which of these ids a foreign key still points at.
     *
     * @param list<string> $ids
     * @return array<string, true>
     */
    public function referencedAmong(string $table, array $ids): array
    {
        $this->requireTable($table);
        $clean = $this->cleanIds($ids);
        if ($clean === []) {
            return [];
        }
        $held = [];
        $marks = implode(', ', array_fill(0, count($clean), '?::uuid'));
        foreach ($this->links($table) as $link) {
            $held += $this->heldIds($link['relation'], $link['column'], $marks, $clean);
        }

        return $held;
    }

    /**
     * How many item rows still name each id. Draft lines are not items.
     *
     * @return array<string, int>
     */
    public function itemCounts(string $table): array
    {
        $this->requireTable($table);
        $counts = [];
        foreach ($this->links($table) as $link) {
            if (!str_ends_with($link['relation'], '.items')) {
                continue;
            }
            foreach (
                $this->statements->rows(
                    'SELECT ' . $link['column'] . '::text AS id, count(*)::int AS used FROM '
                    . $link['relation'] . ' WHERE ' . $link['column'] . ' IS NOT NULL GROUP BY ' . $link['column']
                ) as $row
            ) {
                $id = $row['id'] ?? null;
                $used = $this->countOf($row['used'] ?? null);
                if (!is_string($id) || $id === '' || $used === null) {
                    continue;
                }
                $counts[$id] = ($counts[$id] ?? 0) + $used;
            }
        }

        return $counts;
    }

    public function retarget(string $table, string $source, string $target): void
    {
        $this->requireTable($table);
        $this->requireId($source);
        $this->requireId($target);
        if (strcasecmp($source, $target) === 0) {
            throw new \InvalidArgumentException('that catalog id is not a name');
        }
        foreach ($this->links($table) as $link) {
            $this->statements->change(
                'UPDATE ' . $link['relation'] . ' SET ' . $link['column'] . ' = ?::uuid WHERE ' . $link['column'] . ' = ?::uuid',
                [$target, $source]
            );
        }
    }

    private function requireId(string $id): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new \InvalidArgumentException('that catalog id is not a name');
        }
    }

    private function requireTable(string $table): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1) {
            throw new \InvalidArgumentException('that catalog table is not a name');
        }
    }

    private function countOf(mixed $used): ?int
    {
        if (is_int($used)) {
            return $used;
        }
        if (is_string($used) && preg_match('/^[0-9]+$/', $used) === 1) {
            return (int) $used;
        }

        return null;
    }

    /**
     * @return list<array{relation: string, column: string}>
     */
    private function links(string $table): array
    {
        $links = [];
        foreach (
            $this->statements->bound(
                "SELECT quote_ident(namespace.nspname) || '.' || quote_ident(child.relname) AS relation,
                    quote_ident(attribute.attname) AS column_name
             FROM pg_constraint AS link
             JOIN pg_class AS child ON child.oid = link.conrelid
             JOIN pg_namespace AS namespace ON namespace.oid = child.relnamespace
             JOIN pg_attribute AS attribute
               ON attribute.attrelid = link.conrelid
              AND attribute.attnum = link.conkey[1]
              AND NOT attribute.attisdropped
             WHERE link.confrelid = to_regclass(?)
               AND link.contype = 'f'
               AND cardinality(link.conkey) = 1",
                [$table]
            ) as $link
        ) {
            $relation = $link['relation'] ?? null;
            $column = $link['column_name'] ?? null;
            if (!is_string($relation) || !is_string($column) || $relation === '' || $column === '') {
                continue;
            }
            $links[] = ['relation' => $relation, 'column' => $column];
        }

        return $links;
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function cleanIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) === 1) {
                $clean[] = strtolower($id);
            }
        }

        return $clean;
    }

    /**
     * @param list<string> $ids
     * @return array<string, true>
     */
    private function heldIds(string $relation, string $column, string $marks, array $ids): array
    {
        $held = [];
        foreach (
            $this->statements->bound(
                'SELECT DISTINCT ' . $column . '::text AS id FROM ' . $relation
                . ' WHERE ' . $column . ' IN (' . $marks . ')',
                $ids,
            ) as $row
        ) {
            $id = $row['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $held[strtolower($id)] = true;
            }
        }

        return $held;
    }

    /**
     * @return array<string, true>
     */
    private function ids(string $relation, string $column): array
    {
        $held = [];
        foreach (
            $this->statements->rows(
                'SELECT DISTINCT ' . $column . '::text AS id FROM ' . $relation . ' WHERE ' . $column . ' IS NOT NULL'
            ) as $row
        ) {
            $id = $row['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $held[$id] = true;
            }
        }

        return $held;
    }
}
