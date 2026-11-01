<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Acceptance;

use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Tests\Codeception\ProbeHarness;
use PerfectConsign\Tests\Codeception\Support\AcceptanceTester;
use PerfectConsign\Tests\Codeception\Support\MallRouteCatalog;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;

/**
 * Chrome: click every safe control on main surfaces; load every static mall GET.
 */
final class ClickSweepCest
{
    public function ownerKeyPagesClickEverySafeControl(AcceptanceTester $I): void
    {
        $I->wantTo('click every safe control on the main owner screens');
        $I->signInAsOwner();
        $this->enableCatalogs();
        $slug = ProbeMall::SLUG;
        $failures = [];
        // Nav/seg hubs — RouteCatalog + everyStatic cover full GET surface.
        foreach ([
            '/' . $slug . '/dashboard',
            '/' . $slug . '/pos',
            '/' . $slug . '/settings',
        ] as $path) {
            $I->amOnPage($path);
            if (str_contains($I->grabPageSource(), 'This address is not a page in')) {
                $failures[] = $path . ' rendered not found';
                continue;
            }
            foreach ($I->clickEverySafeControlOnPage($path) as $failure) {
                $failures[] = $path . ': ' . $failure;
            }
        }
        $I->assertSame([], $failures, "broken owner clicks:\n" . implode("\n", $failures));
    }

    public function everyStaticMallGetLoadsInChrome(AcceptanceTester $I): void
    {
        $I->wantTo('open every static mall GET in Chrome without miss/fatal');
        $I->signInAsOwner();
        $this->enableCatalogs();
        $failures = [];
        foreach (MallRouteCatalog::staticMallGets(ProbeMall::SLUG) as $path) {
            try {
                $I->amOnPage($path);
            } catch (\Throwable) {
                try {
                    $I->amOnPage($path);
                } catch (\Throwable $again) {
                    $failures[] = $path . ' nav failed: ' . $again->getMessage();
                    continue;
                }
            }
            $source = $I->grabPageSource();
            if (str_contains($source, 'This address is not a page in')) {
                $failures[] = $path . ' rendered not found';
            }
            if (str_contains($source, 'Fatal error') || str_contains($source, 'Uncaught')) {
                $failures[] = $path . ' blew up';
            }
        }
        $I->assertSame([], $failures, "broken Chrome catalog GETs:\n" . implode("\n", $failures));
    }

    public function vendorPortalClicksEverySafeControl(AcceptanceTester $I): void
    {
        $seed = $I->probeSeed();
        $I->signInAs($seed->vendorUsername, $seed->vendorPassword);
        $slug = ProbeMall::SLUG;
        $failures = [];
        foreach ([
            '/' . $slug . '/vendor',
            '/' . $slug . '/vendor/inventory',
            '/' . $slug . '/vendor/sales',
            '/' . $slug . '/vendor/offers',
        ] as $path) {
            $I->amOnPage($path);
            foreach ($I->clickEverySafeControlOnPage($path) as $failure) {
                $failures[] = $path . ': ' . $failure;
            }
        }
        $I->assertSame([], $failures, "broken vendor clicks:\n" . implode("\n", $failures));
    }

    public function ownerPreviewVendorClicksKeepWorking(AcceptanceTester $I): void
    {
        $seed = $I->probeSeed();
        $I->signInAsOwner();
        $start = VendorPreview::with('/' . ProbeMall::SLUG . '/vendor', $seed->vendorId);
        $I->amOnPage($start);
        $failures = $I->clickEverySafeControlOnPage($start);
        $I->assertSame([], $failures, "broken preview clicks:\n" . implode("\n", $failures));
    }

    private function enableCatalogs(): void
    {
        $client = new FormClient(ProbeHarness::require()->baseUrl());
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        MallRouteCatalog::enableAllCatalogs($client);
    }
}
