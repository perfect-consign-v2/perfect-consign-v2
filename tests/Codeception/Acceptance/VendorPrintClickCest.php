<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Acceptance;

use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Tests\Codeception\Support\AcceptanceTester;
use PerfectConsign\Tests\Support\ProbeMall;

final class VendorPrintClickCest
{
    public function vendorPrintButtonStaysOnStickers(AcceptanceTester $I): void
    {
        $seed = $I->probeSeed();
        $I->signInAs($seed->vendorUsername, $seed->vendorPassword);
        $I->amOnPage('/' . ProbeMall::SLUG . '/vendor/inventory');
        $I->submitStickerJob('/' . ProbeMall::SLUG . '/vendor/labels', $seed->itemId);
        $I->see('Print stickers');
        $I->clickWithoutNavigation('[data-print]');
        $I->see('Print stickers');
        $I->dontSee('This address is not a page in');
    }

    public function ownerPreviewPrintBackKeepsAs(AcceptanceTester $I): void
    {
        $seed = $I->probeSeed();
        $I->signInAsOwner();
        $inventory = VendorPreview::with('/' . ProbeMall::SLUG . '/vendor/inventory', $seed->vendorId);
        $labels = VendorPreview::with('/' . ProbeMall::SLUG . '/vendor/labels', $seed->vendorId);
        $I->amOnPage($inventory);
        $I->submitStickerJob($labels, $seed->itemId);
        $I->see('Print stickers');
        $I->clickWithoutNavigation('[data-print]');
        $I->click('a.btn[href*="vendor/inventory"]');
        $I->see('Your items');
        $I->seeInCurrentUrl('as=');
        $I->seeInCurrentUrl($seed->vendorId);
        $I->dontSee('This address is not a page in');
    }
}
