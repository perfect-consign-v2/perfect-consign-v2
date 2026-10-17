<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Brand;

use PerfectConsign\Shared\Database\CatalogLinks;
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

        return RowValue::integer($rows[0]['total'] ?? 0);
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
        return $this->links->referenced('brands');
    }

    private function requireName(string $name): void
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Enter a brand name.');
        }
    }
}
