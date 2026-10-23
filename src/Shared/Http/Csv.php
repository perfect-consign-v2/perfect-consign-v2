<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

use RuntimeException;

final class Csv
{
    /**
     * @param list<string> $columns
     * @param list<array<string, int|string>> $rows
     */
    public static function document(array $columns, array $rows): string
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new RuntimeException('could not open a csv buffer');
        }
        try {
            self::write($handle, $columns);
            foreach ($rows as $row) {
                $cells = [];
                foreach ($columns as $column) {
                    $cells[] = self::cell((string) ($row[$column] ?? ''));
                }
                self::write($handle, $cells);
            }
            rewind($handle);
            $body = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
        if (!is_string($body)) {
            throw new RuntimeException('could not read a csv buffer');
        }

        return $body;
    }

    private static function cell(string $value): string
    {
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * @param resource $handle
     * @param list<string> $cells
     */
    private static function write($handle, array $cells): void
    {
        if (fputcsv($handle, $cells, ',', '"', '', "\n") === false) {
            throw new RuntimeException('could not write a csv row');
        }
    }
}
