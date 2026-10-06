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
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1) {
            throw new \InvalidArgumentException('that catalog table is not a name');
        }
        $held = [];
        foreach ($this->links($table) as $link) {
            $held += $this->ids($link['relation'], $link['column']);
        }

        return $held;
    }

    /**
     * @return list<array{relation: string, column: string}>
     */
    private function links(string $table): array
    {
        $links = [];
        foreach ($this->statements->bound(
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
        ) as $link) {
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
     * @return array<string, true>
     */
    private function ids(string $relation, string $column): array
    {
        $held = [];
        foreach ($this->statements->rows(
            'SELECT DISTINCT ' . $column . '::text AS id FROM ' . $relation . ' WHERE ' . $column . ' IS NOT NULL'
        ) as $row) {
            $id = $row['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $held[$id] = true;
            }
        }

        return $held;
    }
}
