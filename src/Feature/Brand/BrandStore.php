<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Brand;

use PerfectConsign\Shared\Database\Statements;

final class BrandStore
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function brands(string $query): array
    {
        $needle = strtolower(trim($query));
        if ($needle === '') {
            return $this->statements->rows('SELECT id, name FROM brands ORDER BY lower(name), name');
        }

        return $this->statements->bound(
            'SELECT id, name FROM brands WHERE strpos(lower(name), ?) > 0 ORDER BY lower(name), name',
            [$needle]
        );
    }

    public function count(): int
    {
        $rows = $this->statements->rows('SELECT count(*)::int AS total FROM brands');
        $total = $rows[0]['total'] ?? 0;

        return is_int($total) ? $total : (int) $total;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $rows = $this->statements->bound(
            'SELECT id, name FROM brands WHERE id = ?::uuid',
            [$id]
        );

        return $rows[0] ?? null;
    }

    public function add(string $name): void
    {
        $this->statements->change('INSERT INTO brands (name) VALUES (?)', [$name]);
    }

    public function rename(string $id, string $name): void
    {
        $count = $this->statements->affected(
            'UPDATE brands SET name = ? WHERE id = ?::uuid',
            [$name, $id]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('That brand is not in this mall.');
        }
    }

    public function remove(string $id): void
    {
        if (isset($this->referenced()[$id])) {
            throw new \InvalidArgumentException('That brand is in use.');
        }
        $count = $this->statements->affected('DELETE FROM brands WHERE id = ?::uuid', [$id]);
        if ($count === 0) {
            throw new \InvalidArgumentException('That brand is not in this mall.');
        }
    }

    /**
     * Brand ids that another row still points at.
     *
     * @return array<string, true>
     */
    public function referenced(): array
    {
        $links = $this->statements->rows(
            "SELECT quote_ident(namespace.nspname) || '.' || quote_ident(child.relname) AS relation,
                    quote_ident(attribute.attname) AS column_name
             FROM pg_constraint AS link
             JOIN pg_class AS child ON child.oid = link.conrelid
             JOIN pg_namespace AS namespace ON namespace.oid = child.relnamespace
             JOIN pg_attribute AS attribute
               ON attribute.attrelid = link.conrelid
              AND attribute.attnum = link.conkey[1]
              AND attribute.attisdropped = false
             WHERE link.confrelid = 'brands'::regclass
               AND link.contype = 'f'
               AND cardinality(link.conkey) = 1"
        );
        $held = [];
        foreach ($links as $link) {
            $relation = $link['relation'] ?? null;
            $column = $link['column_name'] ?? null;
            if (!is_string($relation) || !is_string($column) || !$this->quoted($relation, $column)) {
                continue;
            }
            $rows = $this->statements->rows(
                'SELECT DISTINCT ' . $column . '::text AS id FROM ' . $relation . ' WHERE ' . $column . ' IS NOT NULL'
            );
            foreach ($rows as $row) {
                $id = $row['id'] ?? null;
                if (is_string($id) && $id !== '') {
                    $held[$id] = true;
                }
            }
        }

        return $held;
    }

    private function quoted(string $relation, string $column): bool
    {
        return preg_match('/^"[a-z_][a-z0-9_]*"\."[a-z_][a-z0-9_]*"$/', $relation) === 1
            && preg_match('/^"[a-z_][a-z0-9_]*"$/', $column) === 1;
    }
}
