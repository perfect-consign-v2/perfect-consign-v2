<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Functional;

use PerfectConsign\Tests\Codeception\Support\FunctionalTester;
use PerfectConsign\Tests\Codeception\Support\SurfaceFailures;

final class SurfaceLinkCrawlCest
{
    public function everyRoleLinkGraphStaysOnKnownPages(FunctionalTester $I): void
    {
        $I->wantTo('crawl GET links for owner, cashier, books, vendor, and owner preview');
        $failures = SurfaceFailures::linkCrawl();
        $I->assertSame([], $failures, "broken surface links:\n" . implode("\n", $failures));
    }
}
