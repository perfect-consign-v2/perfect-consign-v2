<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Acceptance;

use PerfectConsign\Tests\Codeception\Support\AcceptanceTester;
use PerfectConsign\Tests\Support\ProbeMall;

/**
 * Closing out a vendor in Chrome: the page lists what is left, one button
 * counts it all out as Took it home, and Make inactive turns on.
 */
final class CloseOutAcceptanceCest
{
    public function ownerClosesOutAVendorWhoTookEverythingHome(AcceptanceTester $I): void
    {
        $I->signInAsOwner();
        $slug = ProbeMall::SLUG;
        $code = 'z' . bin2hex(random_bytes(3));
        $I->amOnPage('/' . $slug . '/vendors/new');
        $I->submitPageForm('/' . $slug . '/vendors', ['code' => $code, 'first_name' => 'Zoe', 'last_name' => $code, 'store_take' => '40']);
        $I->see('Vendor created.');
        $vendorId = $I->grabFromCurrentUrl('~/vendors/([0-9a-f-]{36})~');
        $I->amOnPage('/' . $slug . '/inventory/new');
        $I->submitPageForm('/' . $slug . '/inventory', [
            'code' => 't' . $code,
            'description' => 'Zoe lamp ' . $code,
            'vendor_id' => (string) $vendorId,
            'price' => '8.00',
            'quantity' => '2',
            'barcode' => 'BC-t' . $code,
            'intake_on' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles')))->format('Y-m-d'),
            'placement' => 'consigned',
        ]);
        $I->see('Item created.');

        $I->amOnPage('/' . $slug . '/vendors/' . $vendorId . '/close-out');
        $I->see('They still have 2 items in the store. Close them out first.');
        $I->click('form[action$="/close-out/all"] button[type="submit"]');
        $I->see('Every item is marked Took it home.');
        $I->see('No items are left. Nothing to count out.');
        $I->click('form[action$="/standing"] button[type="submit"]');
        $I->see('Vendor is inactive.');
    }
}
