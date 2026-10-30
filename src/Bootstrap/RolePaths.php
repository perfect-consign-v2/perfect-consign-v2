<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

final class RolePaths
{
    public static function underFloor(string $rest): bool
    {
        return self::underPrefix($rest, [
            '/parties/',
            '/vendors/',
            '/inventory/',
            '/booths/',
            '/customers/',
            '/wait-list/',
            '/offers/',
            '/letters/',
        ]);
    }

    /**
     * @return list<string>
     */
    public static function reports(): array
    {
        return [
            '/reports',
            '/reports/sales-tax',
            '/reports/sales',
            '/reports/rent',
            '/reports/payouts',
            '/reports/stored-value',
            '/reports/expiring',
            '/reports/donated',
            '/reports/sell-through',
            '/reports/sales-tax.csv',
            '/reports/sales.csv',
            '/reports/rent.csv',
            '/reports/payouts.csv',
            '/reports/stored-value.csv',
            '/reports/expiring.csv',
            '/reports/donated.csv',
            '/reports/sell-through.csv',
            '/reports/build',
        ];
    }

    /**
     * @param list<string> $prefixes
     */
    public static function underPrefix(string $rest, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($rest, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
