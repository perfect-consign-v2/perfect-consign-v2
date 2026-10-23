<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class SavedReport
{
    public function __construct(
        private readonly Statements $statements,
        private readonly ReportCatalog $catalog,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, source: string, grouped: string}>
     */
    public function list(): array
    {
        $listed = [];
        foreach ($this->statements->rows('SELECT id::text AS id, name, source, grouped FROM saved_reports ORDER BY name') as $row) {
            $listed[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'name' => RowValue::string($row['name'] ?? null),
                'source' => RowValue::string($row['source'] ?? null),
                'grouped' => RowValue::string($row['grouped'] ?? null),
            ];
        }

        return $listed;
    }

    public function save(string $name, string $source, string $grouped): void
    {
        $this->catalog->accept($name, $source, $grouped);
        $this->statements->change(
            'INSERT INTO saved_reports (name, source, grouped) VALUES (?, ?, ?)',
            [trim($name), $source, $grouped]
        );
    }

    /**
     * @return array{id: string, name: string, source: string, grouped: string}|null
     */
    public function find(string $id): ?array
    {
        $rows = $this->statements->bound(
            'SELECT id::text AS id, name, source, grouped FROM saved_reports WHERE id = ?::uuid',
            [$id]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => RowValue::string($row['id'] ?? null),
            'name' => RowValue::string($row['name'] ?? null),
            'source' => RowValue::string($row['source'] ?? null),
            'grouped' => RowValue::string($row['grouped'] ?? null),
        ];
    }
}
