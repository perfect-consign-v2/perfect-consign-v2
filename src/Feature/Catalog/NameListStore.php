<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Catalog;

use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class NameListStore
{
    public function __construct(
        private readonly Statements $statements,
        private readonly CatalogLinks $links,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(NameList $list, string $query): array
    {
        $table = $list->table;
        $needle = strtolower(trim($query));
        if ($needle === '') {
            return $this->statements->rows('SELECT id, name FROM ' . $table . ' ORDER BY lower(name), name');
        }

        return $this->statements->bound(
            'SELECT id, name FROM ' . $table . ' WHERE strpos(lower(name), ?) > 0 ORDER BY lower(name), name',
            [$needle]
        );
    }

    public function count(NameList $list): int
    {
        $rows = $this->statements->rows('SELECT count(*)::int AS total FROM ' . $list->table);

        return RowValue::integer($rows[0]['total'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(NameList $list, string $id): ?array
    {
        $rows = $this->statements->bound(
            'SELECT id, name FROM ' . $list->table . ' WHERE id = ?::uuid',
            [$id]
        );

        return $rows[0] ?? null;
    }

    public function add(NameList $list, string $name): string
    {
        $rows = $this->statements->bound(
            'INSERT INTO ' . $list->table . ' (name) VALUES (?) RETURNING id::text AS id',
            [$name]
        );

        return RowValue::string($rows[0]['id'] ?? null);
    }

    public function rename(NameList $list, string $id, string $name): void
    {
        $count = $this->statements->affected(
            'UPDATE ' . $list->table . ' SET name = ? WHERE id = ?::uuid',
            [$name, $id]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('That ' . $list->singular . ' is not in this mall.');
        }
    }

    public function remove(NameList $list, string $id): void
    {
        if (isset($this->referenced($list)[$id])) {
            throw new \InvalidArgumentException('That ' . $list->singular . ' is in use.');
        }
        $count = $this->statements->affected(
            'DELETE FROM ' . $list->table . ' WHERE id = ?::uuid',
            [$id]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('That ' . $list->singular . ' is not in this mall.');
        }
    }

    /**
     * @return array<string, true>
     */
    public function referenced(NameList $list): array
    {
        return $this->links->referenced($list->table);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(NameList $list, string $query): array
    {
        $needle = strtolower(trim($query));
        if ($needle === '') {
            return [];
        }

        return $this->statements->bound(
            'SELECT id::text AS id, name FROM ' . $list->table . ' WHERE strpos(lower(name), ?) > 0 ORDER BY lower(name), name LIMIT 20',
            [$needle]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function named(NameList $list, string $id): ?array
    {
        $rows = $this->statements->bound(
            'SELECT id::text AS id, name FROM ' . $list->table . ' WHERE id = ?::uuid',
            [$id]
        );

        return $rows[0] ?? null;
    }

    public function idForName(NameList $list, string $name): ?string
    {
        $rows = $this->statements->bound(
            'SELECT id::text AS id FROM ' . $list->table . ' WHERE lower(name) = lower(?)',
            [$name]
        );
        $id = $rows[0]['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }

        return $id;
    }
}
