<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Tests\Support\PageSurfaceCrawler;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\ProbeSurfaceSeed;
use PerfectConsign\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('surface-mutate')]
final class SurfaceFormCrawlTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private static ?ProbeSurfaceSeed $seed = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$seed = ProbeSurfaceSeed::ensure(self::client());
    }

    public function testSafeFormsAcrossRolesDoNotFiveHundredOrMiss(): void
    {
        $seed = $this->seed();
        $slug = self::SLUG;
        $failures = [];

        $owner = self::client();
        $owner->signIn($slug, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $failures = array_merge(
            $failures,
            $this->label(
                'owner',
                (new PageSurfaceCrawler($owner, $slug, 'owner'))->crawlForms(['/' . $slug . '/dashboard'])
            )
        );

        $cashier = self::client();
        $cashier->signIn($slug, $seed->cashierUsername, $seed->cashierPassword);
        $failures = array_merge(
            $failures,
            $this->label(
                'cashier',
                (new PageSurfaceCrawler($cashier, $slug, 'cashier'))->crawlForms(['/' . $slug . '/dashboard'])
            )
        );

        $books = self::client();
        $books->signIn($slug, $seed->booksUsername, $seed->booksPassword);
        $failures = array_merge(
            $failures,
            $this->label(
                'books',
                (new PageSurfaceCrawler($books, $slug, 'books'))->crawlForms(['/' . $slug . '/dashboard'])
            )
        );

        $vendor = self::client();
        $vendor->signIn($slug, $seed->vendorUsername, $seed->vendorPassword);
        $failures = array_merge(
            $failures,
            $this->label(
                'vendor',
                (new PageSurfaceCrawler($vendor, $slug, 'vendor'))->crawlForms(['/' . $slug . '/vendor'])
            )
        );

        $preview = self::client();
        $preview->signIn($slug, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $start = VendorPreview::with('/' . $slug . '/vendor', $seed->vendorId);
        $failures = array_merge(
            $failures,
            $this->label(
                'owner-preview',
                (new PageSurfaceCrawler($preview, $slug, 'owner', 35, $seed->vendorId))->crawlForms([$start])
            )
        );

        self::assertSame([], $failures, "broken surface forms:\n" . implode("\n", $failures));
    }

    private function seed(): ProbeSurfaceSeed
    {
        $seed = self::$seed;
        if (!$seed instanceof ProbeSurfaceSeed) {
            self::fail('surface seed was not built');
        }

        return $seed;
    }

    /**
     * @param list<string> $failures
     * @return list<string>
     */
    private function label(string $role, array $failures): array
    {
        $labeled = [];
        foreach ($failures as $failure) {
            $labeled[] = $role . ': ' . $failure;
        }

        return $labeled;
    }
}
