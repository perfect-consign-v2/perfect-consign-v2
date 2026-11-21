<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use InvalidArgumentException;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Http\Csv;
use PerfectConsign\Shared\Http\View;

final class ReportTable
{
    /**
     * @param list<ReportColumn> $columns
     * @param list<array<string, int|string|null>> $rows
     * @return list<list<string>>
     */
    public function cells(array $columns, array $rows): array
    {
        $listed = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = $this->screen($column, $row[$column->key] ?? null);
            }
            $listed[] = $line;
        }

        return $listed;
    }

    /**
     * @param list<ReportColumn> $columns
     * @param list<array<string, int|string|null>> $rows
     * @return list<string>|null
     */
    public function total(array $columns, array $rows): ?array
    {
        if ($rows === [] || $columns === []) {
            return null;
        }
        $line = [];
        foreach ($columns as $index => $column) {
            $line[] = $index === 0 ? 'Total' : $this->sum($column, $rows);
        }

        return $line;
    }

    /**
     * @param list<ReportColumn> $columns
     * @param list<array<string, int|string|null>> $rows
     */
    public function document(array $columns, array $rows): string
    {
        $headers = [];
        $body = [];
        foreach ($columns as $column) {
            $headers[] = $column->label;
        }
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[$column->label] = $this->csv($column, $row[$column->key] ?? null);
            }
            $body[] = $line;
        }

        return Csv::document($headers, $body);
    }

    private function screen(ReportColumn $column, int|string|null $value): string
    {
        if ($column->kind === 'money') {
            return View::money($this->amount($value));
        }
        if ($column->kind === 'date') {
            return View::date($this->text($value));
        }
        if ($column->kind === 'percent') {
            return $value === null ? '' : (string) $this->amount($value);
        }
        if ($column->kind === 'number') {
            return is_int($value) ? (string) $value : 'Not recorded';
        }

        return $this->text($value);
    }

    private function csv(ReportColumn $column, int|string|null $value): string
    {
        if ($column->kind === 'money') {
            return Money::decimal($this->amount($value));
        }
        if ($column->kind === 'percent') {
            return $value === null ? '' : (string) $this->amount($value);
        }
        if ($column->kind === 'number') {
            return is_int($value) ? (string) $value : 'Not recorded';
        }
        if ($column->kind === 'date') {
            return $this->text($value);
        }

        return $this->text($value);
    }

    /**
     * @param list<array<string, int|string|null>> $rows
     */
    private function sum(ReportColumn $column, array $rows): string
    {
        if ($column->kind !== 'money' && $column->kind !== 'number') {
            return '';
        }
        $total = 0;
        foreach ($rows as $row) {
            $value = $row[$column->key] ?? null;
            if (is_int($value)) {
                $total += $value;
            }
        }
        if ($column->kind === 'money') {
            return View::money($total);
        }

        return (string) $total;
    }

    private function amount(int|string|null $value): int
    {
        if (!is_int($value)) {
            throw new InvalidArgumentException('That amount is not on this page.');
        }

        return $value;
    }

    private function text(int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return 'Not recorded';
        }

        return (string) $value;
    }
}
