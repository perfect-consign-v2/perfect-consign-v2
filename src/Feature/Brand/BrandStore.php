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
    public function brands(): array
    {
        return $this->statements->rows('SELECT id, name FROM brands ORDER BY lower(name), name');
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
            throw new \InvalidArgumentException('that brand is not in this mall');
        }
    }

    public function remove(string $id): void
    {
        $count = $this->statements->affected('DELETE FROM brands WHERE id = ?::uuid', [$id]);
        if ($count === 0) {
            throw new \InvalidArgumentException('that brand is not in this mall');
        }
    }
}
