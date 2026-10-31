<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Support;

use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Tests\Codeception\ProbeHarness;
use PerfectConsign\Tests\Support\PageSurfaceCrawler;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\ProbeSurfaceSeed;

/**
 * Role surface crawl shared by Codeception functional Cests.
 */
final class SurfaceFailures
{
    /**
     * @return list<string>
     */
    public static function linkCrawl(): array
    {
        return self::crawl(static fn (PageSurfaceCrawler $crawler, string $start): array => $crawler->crawlGets([$start]));
    }

    /**
     * @return list<string>
     */
    public static function formCrawl(): array
    {
        return self::crawl(static fn (PageSurfaceCrawler $crawler, string $start): array => $crawler->crawlForms([$start]));
    }

    /**
     * @param callable(PageSurfaceCrawler, string): list<string> $walk
     * @return list<string>
     */
    private static function crawl(callable $walk): array
    {
        $harness = ProbeHarness::require();
        $seed = $harness->seed();
        $slug = ProbeMall::SLUG;
        $failures = [];

        // Cap visits below the PHPUnit surface suite (35) so Codeception stays under CI budgets.
        $visits = 20;

        $owner = $harness->client();
        $owner->signIn($slug, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $failures = array_merge(
            $failures,
            self::label(
                'owner',
                $walk(new PageSurfaceCrawler($owner, $slug, 'owner', $visits), '/' . $slug . '/dashboard')
            )
        );

        $cashier = $harness->client();
        $cashier->signIn($slug, $seed->cashierUsername, $seed->cashierPassword);
        $failures = array_merge(
            $failures,
            self::label(
                'cashier',
                $walk(new PageSurfaceCrawler($cashier, $slug, 'cashier', $visits), '/' . $slug . '/dashboard')
            )
        );

        $books = $harness->client();
        $books->signIn($slug, $seed->booksUsername, $seed->booksPassword);
        $failures = array_merge(
            $failures,
            self::label(
                'books',
                $walk(new PageSurfaceCrawler($books, $slug, 'books', $visits), '/' . $slug . '/dashboard')
            )
        );

        $vendor = $harness->client();
        $vendor->signIn($slug, $seed->vendorUsername, $seed->vendorPassword);
        $failures = array_merge(
            $failures,
            self::label(
                'vendor',
                $walk(new PageSurfaceCrawler($vendor, $slug, 'vendor', $visits), '/' . $slug . '/vendor')
            )
        );

        $preview = $harness->client();
        $preview->signIn($slug, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $start = VendorPreview::with('/' . $slug . '/vendor', $seed->vendorId);
        $failures = array_merge(
            $failures,
            self::label(
                'owner-preview',
                $walk(new PageSurfaceCrawler($preview, $slug, 'owner', $visits, $seed->vendorId), $start)
            )
        );

        return $failures;
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
