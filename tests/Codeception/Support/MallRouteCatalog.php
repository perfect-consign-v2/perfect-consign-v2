<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Support;

use PerfectConsign\Bootstrap\RegisterFeatures;
use PerfectConsign\Bootstrap\RoleGate;
use PerfectConsign\Bootstrap\RouteInventory;
use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;

/**
 * Every owner-reachable mall GET that does not need a live entity UUID.
 */
final class MallRouteCatalog
{
    /**
     * @return list<string>
     */
    public static function staticMallGets(string $slug = ProbeMall::SLUG): array
    {
        $root = dirname(__DIR__, 3);
        $feature = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Feature';
        RegisterFeatures::clearCache();
        $paths = [];
        foreach (RouteInventory::sampleGets(RouteInventory::routes($feature), $slug) as $sample) {
            $path = str_replace('\\.', '.', $sample['path']);
            if (str_starts_with($path, '/admin')) {
                continue;
            }
            if ($path === '/' || $path === '/forgot' || $path === '/signup') {
                continue;
            }
            if (!str_starts_with($path, '/' . $slug)) {
                continue;
            }
            // Placeholder UUIDs / tokens are not live rows — skip entity GETs here.
            if (str_contains($path, '01900000-0000-7000-8000-000000000001')) {
                continue;
            }
            if (str_contains($path, str_repeat('A', 43)) || str_contains($path, str_repeat('a', 64))) {
                continue;
            }
            // JSON APIs are covered by FormClient status checks, not HTML miss text.
            if (str_ends_with($path, '.json')) {
                continue;
            }
            // CSV/ZIP downloads never fire a document load in Chrome.
            if (str_ends_with($path, '.csv') || str_ends_with($path, '.zip')) {
                continue;
            }
            // Vendor portal as the store owner requires ?as= (see seededGets).
            if ($path === '/' . $slug . '/vendor' || str_starts_with($path, '/' . $slug . '/vendor/')) {
                continue;
            }
            $rest = self::rest($slug, $path);
            if ($rest === null || !RoleGate::allows('owner', $rest, 'GET')) {
                continue;
            }
            $paths[$path] = $path;
        }
        ksort($paths);

        return array_values($paths);
    }

    /**
     * Turn on every catalog flag so name-list routes are live for the owner.
     */
    public static function enableAllCatalogs(FormClient $owner, string $slug = ProbeMall::SLUG): void
    {
        $owner->post('/' . $slug . '/settings/building', [
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => '10',
            'booth_depth_ft' => '10',
            'floor_width_ft' => '120',
            'floor_depth_ft' => '90',
            'use_color' => '1',
            'use_category' => '1',
            'use_size' => '1',
            'use_condition' => '1',
            'use_department' => '1',
            'use_brand' => '1',
        ]);
    }

    /**
     * Seeded entity pages the surface crawl may not reach first.
     * (GET /vendor/labels redirects and drops ?as= — print is POST-only.)
     *
     * @return list<string>
     */
    public static function seededGets(string $slug, string $vendorId, string $itemId): array
    {
        return [
            '/' . $slug . '/vendors/' . $vendorId,
            '/' . $slug . '/vendors/' . $vendorId . '/edit',
            '/' . $slug . '/inventory/' . $itemId . '/edit',
            VendorPreview::with('/' . $slug . '/vendor', $vendorId),
            VendorPreview::with('/' . $slug . '/vendor/inventory', $vendorId),
            VendorPreview::with('/' . $slug . '/vendor/sales', $vendorId),
            VendorPreview::with('/' . $slug . '/vendor/offers', $vendorId),
        ];
    }

    private static function rest(string $slug, string $path): ?string
    {
        $pathOnly = explode('?', $path, 2)[0];
        $prefix = '/' . $slug;
        if ($pathOnly === $prefix) {
            return '/';
        }
        if (!str_starts_with($pathOnly, $prefix . '/')) {
            return null;
        }
        $rest = substr($pathOnly, strlen($prefix));

        return $rest === '' ? '/' : $rest;
    }
}
