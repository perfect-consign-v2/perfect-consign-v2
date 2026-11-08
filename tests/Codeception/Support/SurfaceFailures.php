<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Support;

use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Tests\Codeception\ProbeHarness;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\ProbeSurfaceSeed;

/**
 * Full role surface crawl for Codeception (discoverable mall hrefs + safe forms).
 * Static route completeness is RouteCatalogCest — crawls start from hubs so table-row
 * explosion (hundreds of vendors/items) cannot hang the suite before forms run.
 */
final class SurfaceFailures
{
    /**
     * @return list<string>
     */
    public static function linkCrawl(): array
    {
        return self::crawl(static fn (FullSurfaceCrawler $crawler, array $starts): array => $crawler->crawlGets($starts));
    }

    /**
     * @return list<string>
     */
    public static function formCrawl(): array
    {
        return self::crawl(static fn (FullSurfaceCrawler $crawler, array $starts): array => $crawler->crawlForms($starts));
    }

    /**
     * @param callable(FullSurfaceCrawler, list<string>): list<string> $walk
     * @return list<string>
     */
    private static function crawl(callable $walk): array
    {
        $harness = ProbeHarness::require();
        $seed = $harness->seed();
        $slug = ProbeMall::SLUG;
        $failures = [];
        $hubs = self::hubStarts($slug, $seed);

        $owner = $harness->client();
        $owner->signIn($slug, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        MallRouteCatalog::enableAllCatalogs($owner, $slug);
        $failures = array_merge(
            $failures,
            self::label('owner', $walk(new FullSurfaceCrawler($owner, $slug, 'owner'), $hubs['owner']))
        );

        $cashier = $harness->client();
        $cashier->signIn($slug, $seed->cashierUsername, $seed->cashierPassword);
        $failures = array_merge(
            $failures,
            self::label(
                'cashier',
                $walk(new FullSurfaceCrawler($cashier, $slug, 'cashier'), $hubs['cashier'])
            )
        );

        $books = $harness->client();
        $books->signIn($slug, $seed->booksUsername, $seed->booksPassword);
        $failures = array_merge(
            $failures,
            self::label(
                'books',
                $walk(new FullSurfaceCrawler($books, $slug, 'books'), $hubs['books'])
            )
        );

        $vendor = $harness->client();
        $vendor->signIn($slug, $seed->vendorUsername, $seed->vendorPassword);
        $failures = array_merge(
            $failures,
            self::label(
                'vendor',
                $walk(new FullSurfaceCrawler($vendor, $slug, 'vendor'), $hubs['vendor'])
            )
        );

        $preview = $harness->client();
        $preview->signIn($slug, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $failures = array_merge(
            $failures,
            self::label(
                'owner-preview',
                $walk(
                    new FullSurfaceCrawler($preview, $slug, 'owner', $seed->vendorId),
                    $hubs['preview']
                )
            )
        );

        return $failures;
    }

    /**
     * @return array{
     *     owner: list<string>,
     *     cashier: list<string>,
     *     books: list<string>,
     *     vendor: list<string>,
     *     preview: list<string>
     * }
     */
    private static function hubStarts(string $slug, ProbeSurfaceSeed $seed): array
    {
        $base = '/' . $slug;

        return [
            'owner' => array_values(array_unique(array_merge(
                [
                    $base . '/dashboard',
                    $base . '/inventory',
                    $base . '/vendors',
                    $base . '/parties',
                    $base . '/booths',
                    $base . '/registers',
                    $base . '/settings',
                    $base . '/staff',
                    $base . '/rent',
                    $base . '/payout',
                    $base . '/journals',
                    $base . '/pos',
                    $base . '/sales',
                    $base . '/reports',
                    $base . '/brands',
                    $base . '/colors',
                    $base . '/categories',
                    $base . '/sizes',
                    $base . '/conditions',
                    $base . '/departments',
                    $base . '/wait-list',
                    $base . '/offers',
                    $base . '/map',
                    $base . '/company',
                    $base . '/tax',
                    $base . '/book',
                ],
                MallRouteCatalog::seededGets($slug, $seed->vendorId, $seed->itemId),
            ))),
            'cashier' => [
                $base . '/dashboard',
                $base . '/pos',
                $base . '/inventory',
                $base . '/vendors',
                $base . '/registers',
            ],
            'books' => [
                $base . '/dashboard',
                $base . '/journals',
                $base . '/rent',
                $base . '/payout',
                $base . '/reports',
                $base . '/tax',
            ],
            'vendor' => [
                $base . '/vendor',
                $base . '/vendor/inventory',
                $base . '/vendor/sales',
                $base . '/vendor/offers',
            ],
            'preview' => [
                VendorPreview::with($base . '/vendor', $seed->vendorId),
                VendorPreview::with($base . '/vendor/inventory', $seed->vendorId),
                VendorPreview::with($base . '/vendor/sales', $seed->vendorId),
                VendorPreview::with($base . '/vendor/offers', $seed->vendorId),
            ],
        ];
    }

    /**
     * @param list<string> $failures
     * @return list<string>
     */
    private static function label(string $role, array $failures): array
    {
        $labeled = [];
        foreach ($failures as $failure) {
            $labeled[] = $role . ': ' . $failure;
        }

        return $labeled;
    }

    public static function seed(): ProbeSurfaceSeed
    {
        return ProbeHarness::require()->seed();
    }
}
