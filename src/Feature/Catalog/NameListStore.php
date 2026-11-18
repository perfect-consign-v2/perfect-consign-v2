<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Catalog;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\PgBool;
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
            return $this->statements->rows('SELECT id, name, active FROM ' . $table . ' ORDER BY lower(name), name');
        }

        return $this->statements->bound(
            'SELECT id, name, active FROM ' . $table . ' WHERE strpos(lower(name), ?) > 0 ORDER BY lower(name), name',
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
            'SELECT id, name, active FROM ' . $list->table . ' WHERE id = ?::uuid',
            [$id]
        );

        return $rows[0] ?? null;
    }

    public function add(NameList $list, string $name): string
    {
        $this->requireName($list, $name);
        $rows = $this->statements->bound(
            'INSERT INTO ' . $list->table . ' (name) VALUES (?) RETURNING id::text AS id',
            [$name]
        );

        return RowValue::string($rows[0]['id'] ?? null);
    }

    public function rename(NameList $list, string $id, string $name): void
    {
        $this->requireName($list, $name);
        $count = $this->statements->affected(
            'UPDATE ' . $list->table . ' SET name = ? WHERE id = ?::uuid',
            [$name, $id]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('That ' . $list->singular . ' is not in this mall.');
        }
    }

    public function setActive(NameList $list, string $id, bool $active): void
    {
        $count = $this->statements->affected(
            'UPDATE ' . $list->table . ' SET active = ?::boolean WHERE id = ?::uuid',
            [PgBool::literal($active), $id]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('That ' . $list->singular . ' is not in this mall.');
        }
    }

    public function remove(NameList $list, string $id): void
    {
        try {
            $this->statements->transaction(function () use ($list, $id): void {
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
            });
        } catch (DatabaseRefusal $error) {
            $this->inUse($error, $list->singular);
        }
    }

    public function merge(NameList $list, string $source, string $target): void
    {
        $this->requireId($source, $list);
        $this->requireId($target, $list);
        if (strcasecmp($source, $target) === 0) {
            throw new \InvalidArgumentException('Choose a different ' . $list->singular . '.');
        }
        try {
            $this->statements->transaction(function () use ($list, $source, $target): void {
                if ($this->find($list, $source) === null || $this->find($list, $target) === null) {
                    throw new \InvalidArgumentException('That ' . $list->singular . ' is not in this mall.');
                }
                $this->links->retarget($list->table, $source, $target);
                $this->statements->change(
                    'UPDATE ' . $list->table . ' SET active = true WHERE id = ?::uuid',
                    [$target]
                );
                $count = $this->statements->affected(
                    'DELETE FROM ' . $list->table . ' WHERE id = ?::uuid',
                    [$source]
                );
                if ($count === 0) {
                    throw new \InvalidArgumentException('That ' . $list->singular . ' is not in this mall.');
                }
            });
        } catch (DatabaseRefusal $error) {
            $this->inUse($error, $list->singular);
        }
    }

    /**
     * @return array<string, int>
     */
    public function itemCounts(NameList $list): array
    {
        return $this->links->itemCounts($list->table);
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
            'SELECT id::text AS id, name FROM ' . $list->table
            . ' WHERE active AND strpos(lower(name), ?) > 0 ORDER BY lower(name), name LIMIT 20',
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
            'SELECT id::text AS id, active FROM ' . $list->table . ' WHERE lower(name) = lower(?)',
            [$name]
        );
        if ($rows === []) {
            return null;
        }
        if (!CatalogFlag::on($rows[0]['active'] ?? null)) {
            throw new \InvalidArgumentException('That ' . $list->singular . ' is inactive.');
        }
        $id = $rows[0]['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }

        return $id;
    }

    private function requireName(NameList $list, string $name): void
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Enter a ' . $list->singular . ' name.');
        }
    }

    private function requireId(string $id, NameList $list): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Choose a different ' . $list->singular . '.');
        }
    }

    private function inUse(DatabaseRefusal $error, string $noun): never
    {
        if ($error->sqlState() === '23503') {
            throw new \InvalidArgumentException('That ' . $noun . ' is in use.');
        }

        throw $error;
    }
}
