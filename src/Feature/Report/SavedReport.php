<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use InvalidArgumentException;
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
     * @return list<array{id: string, name: string, sheet: string, grouped: string, range_key: string}>
     */
    public function list(): array
    {
        $listed = [];
        foreach (
            $this->statements->rows(
                'SELECT id::text AS id, name, sheet, grouped, range_key FROM saved_reports ORDER BY name',
            ) as $row
        ) {
            $listed[] = $this->map($row);
        }

        return $listed;
    }

    public function save(string $name, string $sheet, string $grouped, string $range): void
    {
        $this->catalog->accept($name, $sheet, $grouped, $range);
        $this->statements->change(
            'INSERT INTO saved_reports (name, sheet, grouped, range_key) VALUES (?, ?, ?, ?)',
            [trim($name), $sheet, $grouped, $range],
        );
    }

    /**
     * @return array{id: string, name: string, sheet: string, grouped: string, range_key: string}|null
     */
    public function find(string $id): ?array
    {
        $rows = $this->statements->bound(
            'SELECT id::text AS id, name, sheet, grouped, range_key FROM saved_reports WHERE id = ?::uuid',
            [$id],
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return null;
        }

        return $this->map($row);
    }

    public function remove(string $id): void
    {
        $this->statements->change('DELETE FROM saved_reports WHERE id = ?::uuid', [$id]);
    }

    /**
     * @param array{sheet: string, grouped: string, range_key: string} $report
     */
    public function path(array $report): string
    {
        $sheet = $report['sheet'];
        $range = rawurlencode($report['range_key']);
        if ($sheet === 'sales') {
            return '/reports/sales?group=' . rawurlencode($report['grouped']) . '&range=' . $range;
        }
        if ($sheet === 'rent') {
            return '/reports/rent';
        }
        if ($sheet === 'stored_value') {
            return '/reports/stored-value';
        }
        if ($sheet === 'expiring') {
            return '/reports/expiring';
        }
        if ($sheet === 'payouts') {
            return '/reports/payouts?range=' . $range;
        }
        if ($sheet === 'donated') {
            return '/reports/donated?range=' . $range;
        }
        if ($sheet === 'sell_through') {
            return '/reports/sell-through?range=' . $range;
        }

        throw new InvalidArgumentException('That report is not on this page.');
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, name: string, sheet: string, grouped: string, range_key: string}
     */
    private function map(array $row): array
    {
        return [
            'id' => RowValue::string($row['id'] ?? null),
            'name' => RowValue::string($row['name'] ?? null),
            'sheet' => RowValue::string($row['sheet'] ?? null),
            'grouped' => RowValue::string($row['grouped'] ?? null),
            'range_key' => RowValue::string($row['range_key'] ?? null),
        ];
    }
}
