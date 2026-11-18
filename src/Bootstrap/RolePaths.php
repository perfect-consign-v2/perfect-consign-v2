<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

final class RolePaths
{
    public static function inventoryBuy(string $rest): bool
    {
        return preg_match('#^/inventory/[0-9a-fA-F-]{36}/buy$#', $rest) === 1;
    }

    public static function underFloor(string $rest): bool
    {
        return self::underPrefix($rest, [
            '/parties/',
            '/vendors/',
            '/consignors/',
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

    public static function cashierCatalog(string $rest, string $method): bool
    {
        return self::nameCatalog($rest, $method) || self::sellerLookup($rest, $method);
    }

    public static function vendorReads(string $rest, string $method): bool
    {
        if ($method === 'GET' && preg_match('#^/inventory/[0-9a-fA-F-]{36}/photo$#', $rest) === 1) {
            return true;
        }

        return self::nameCatalog($rest, $method);
    }

    public static function nameCatalog(string $rest, string $method): bool
    {
        $catalogs = ['brands', 'colors', 'categories', 'sizes', 'conditions', 'departments'];
        if ($method === 'GET') {
            return in_array($rest, self::catalogPaths($catalogs, '.json'), true);
        }

        return $method === 'POST' && in_array($rest, self::catalogPaths($catalogs, ''), true);
    }

    public static function sellerLookup(string $rest, string $method): bool
    {
        return $method === 'GET' && in_array($rest, [
            '/inventory/vendors.json',
            '/inventory/consignors.json',
        ], true);
    }

    /**
     * @param list<string> $catalogs
     * @return list<string>
     */
    private static function catalogPaths(array $catalogs, string $suffix): array
    {
        $paths = [];
        foreach ($catalogs as $catalog) {
            $paths[] = '/inventory/' . $catalog . $suffix;
        }

        return $paths;
    }
}
