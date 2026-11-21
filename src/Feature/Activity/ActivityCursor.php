<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Activity;

final class ActivityCursor
{
    /**
     * @param list<array{id: string}> $rows
     * @param array{id: string}|null $bound
     */
    public static function older(string $direction, array $rows, bool $extra, ?array $bound): ?string
    {
        if ($direction === 'after') {
            return self::edge($rows, false) ?? self::boundId($bound);
        }
        if (!$extra) {
            return null;
        }

        return self::edge($rows, false);
    }

    /**
     * @param list<array{id: string}> $rows
     * @param array{id: string}|null $bound
     */
    public static function newer(string $direction, array $rows, bool $extra, ?array $bound): ?string
    {
        if ($direction === 'after') {
            if (!$extra) {
                return null;
            }

            return self::edge($rows, true);
        }
        if ($direction !== 'before') {
            return null;
        }

        return self::edge($rows, true) ?? self::boundId($bound);
    }

    /**
     * @param array{id: string}|null $bound
     */
    private static function boundId(?array $bound): ?string
    {
        if ($bound === null) {
            return null;
        }

        return $bound['id'];
    }

    /**
     * @param list<array{id: string}> $rows
     */
    private static function edge(array $rows, bool $newest): ?string
    {
        if ($rows === []) {
            return null;
        }
        $row = $newest ? $rows[0] : $rows[array_key_last($rows)];

        return $row['id'];
    }
}
