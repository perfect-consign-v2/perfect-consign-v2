<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Brand;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\PgBool;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class BrandStore
{
    public function __construct(
        private readonly Statements $statements,
        private readonly CatalogLinks $links,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function brands(string $query): array
    {
        $needle = strtolower(trim($query));
        if ($needle === '') {
            return $this->statements->rows('SELECT id, name, active FROM brands ORDER BY lower(name), name');
        }

        return $this->statements->bound(
            'SELECT id, name, active FROM brands WHERE strpos(lower(name), ?) > 0 ORDER BY lower(name), name',
            [$needle]
        );
    }

    public function count(): int
    {
        $rows = $this->statements->rows('SELECT count(*)::int AS total FROM brands');

        return RowValue::integer($rows[0]['total'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $rows = $this->statements->bound(
            'SELECT id, name, active FROM brands WHERE id = ?::uuid',
            [$id]
        );

        return $rows[0] ?? null;
    }

    public function add(string $name): void
    {
        $this->requireName($name);
        $this->statements->change('INSERT INTO brands (name) VALUES (?)', [$name]);
    }

    public function rename(string $id, string $name): void
    {
        $this->requireName($name);
        $count = $this->statements->affected(
            'UPDATE brands SET name = ? WHERE id = ?::uuid',
            [$name, $id]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('That brand is not in this mall.');
        }
    }

    public function setActive(string $id, bool $active): void
    {
        $count = $this->statements->affected(
            'UPDATE brands SET active = ?::boolean WHERE id = ?::uuid',
            [PgBool::literal($active), $id]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('That brand is not in this mall.');
        }
    }

    public function remove(string $id): void
    {
        try {
            $this->statements->transaction(function () use ($id): void {
                if (isset($this->referenced()[$id])) {
                    throw new \InvalidArgumentException('That brand is in use.');
                }
                $count = $this->statements->affected('DELETE FROM brands WHERE id = ?::uuid', [$id]);
                if ($count === 0) {
                    throw new \InvalidArgumentException('That brand is not in this mall.');
                }
            });
        } catch (DatabaseRefusal $error) {
            $this->inUse($error);
        }
    }

    public function merge(string $source, string $target): void
    {
        $this->requireId($source);
        $this->requireId($target);
        if (strcasecmp($source, $target) === 0) {
            throw new \InvalidArgumentException('Choose a different brand.');
        }
        try {
            $this->statements->transaction(function () use ($source, $target): void {
                if ($this->find($source) === null || $this->find($target) === null) {
                    throw new \InvalidArgumentException('That brand is not in this mall.');
                }
                $this->links->retarget('brands', $source, $target);
                $this->statements->change('UPDATE brands SET active = true WHERE id = ?::uuid', [$target]);
                $count = $this->statements->affected('DELETE FROM brands WHERE id = ?::uuid', [$source]);
                if ($count === 0) {
                    throw new \InvalidArgumentException('That brand is not in this mall.');
                }
            });
        } catch (DatabaseRefusal $error) {
            $this->inUse($error);
        }
    }

    /**
     * @return array<string, int>
     */
    public function itemCounts(): array
    {
        return $this->links->itemCounts('brands');
    }

    /**
     * Brand ids that another row still points at.
     *
     * @return array<string, true>
     */
    public function referenced(): array
    {
        return $this->links->referenced('brands');
    }

    private function requireName(string $name): void
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Enter a brand name.');
        }
    }

    private function requireId(string $id): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Choose a different brand.');
        }
    }

    private function inUse(DatabaseRefusal $error): never
    {
        if ($error->sqlState() === '23503') {
            throw new \InvalidArgumentException('That brand is in use.');
        }

        throw $error;
    }
}
