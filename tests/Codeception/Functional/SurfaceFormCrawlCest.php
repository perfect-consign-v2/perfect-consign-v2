<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Functional;

use PerfectConsign\Tests\Codeception\Support\FunctionalTester;
use PerfectConsign\Tests\Codeception\Support\SurfaceFailures;

final class SurfaceFormCrawlCest
{
    public function safeFormsAcrossRolesDoNotFiveHundredOrMiss(FunctionalTester $I): void
    {
        $I->wantTo('submit safe surface forms for every mall role without 500 or miss pages');
        $failures = SurfaceFailures::formCrawl();
        $I->assertSame([], $failures, "broken surface forms:\n" . implode("\n", $failures));
    }
}
