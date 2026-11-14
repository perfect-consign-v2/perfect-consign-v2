<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

use RuntimeException;

final class Csv
{
    private static bool $refuseRead = false;

    public static function refuseRead(bool $refuse): void
    {
        self::$refuseRead = $refuse;
    }

    /**
     * @param list<string> $columns
     * @param list<array<string, int|string>> $rows
     */
    public static function document(array $columns, array $rows, string $buffer = 'php://temp'): string
    {
        $handle = fopen($buffer, 'w+');
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
            $body = self::$refuseRead ? false : stream_get_contents($handle);
        } finally {
            self::$refuseRead = false;
            fclose($handle);
        }
        if (!is_string($body)) {
            throw new RuntimeException('could not read a csv buffer');
        }

        return $body;
    }

    public static function protect(string $value): string
    {
        return self::cell($value);
    }

    private static function cell(string $value): string
    {
        if ($value !== '' && preg_match('/^[=+@\t\r]/', $value) === 1) {
            return "'" . $value;
        }
        if ($value !== '' && str_starts_with($value, '-') && preg_match('/^-?(?:\d+|\d+\.\d+)$/', $value) !== 1) {
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
