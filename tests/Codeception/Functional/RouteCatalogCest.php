<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Functional;

use PerfectConsign\Tests\Codeception\Support\FunctionalTester;
use PerfectConsign\Tests\Codeception\Support\MallRouteCatalog;
use PerfectConsign\Tests\Support\ProbeMall;
use RuntimeException;

final class RouteCatalogCest
{
    public function everyStaticMallGetLoadsForOwner(FunctionalTester $I): void
    {
        $I->wantTo('GET every static mall route pattern as the owner without 500 or miss');
        $failures = [];
        $paths = MallRouteCatalog::staticMallGets(ProbeMall::SLUG);
        $I->assertGreaterThan(40, count($paths), 'expected a full static mall GET catalog');
        $client = $I->probeClient();
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        MallRouteCatalog::enableAllCatalogs($client);
        foreach ($paths as $path) {
            try {
                $page = $client->get($path, 90);
            } catch (RuntimeException) {
                try {
                    $page = $client->get($path, 90);
                } catch (RuntimeException $again) {
                    $failures[] = $path . ' GET failed: ' . $again->getMessage();
                    continue;
                }
            }
            if ($page->status >= 500) {
                $failures[] = $path . ' returned ' . (string) $page->status;
            }
            if (str_contains($page->body, 'This address is not a page in')) {
                $failures[] = $path . ' rendered not found';
            }
            if (str_contains($page->body, 'Fatal error') || str_contains($page->body, 'Uncaught')) {
                $failures[] = $path . ' blew up';
            }
        }
        $I->assertSame([], $failures, "broken static mall GETs:\n" . implode("\n", $failures));
    }

    public function seededEntityGetsLoadForOwner(FunctionalTester $I): void
    {
        $seed = $I->probeSeed();
        $client = $I->probeClient();
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $failures = [];
        foreach (MallRouteCatalog::seededGets(ProbeMall::SLUG, $seed->vendorId, $seed->itemId) as $path) {
            try {
                $page = $client->get($path, 90);
            } catch (RuntimeException $error) {
                $failures[] = $path . ' GET failed: ' . $error->getMessage();
                continue;
            }
            if ($page->status >= 500) {
                $failures[] = $path . ' returned ' . (string) $page->status;
            }
            if (str_contains($page->body, 'This address is not a page in')) {
                $failures[] = $path . ' rendered not found';
            }
        }
        $I->assertSame([], $failures, "broken seeded GETs:\n" . implode("\n", $failures));
    }
}
