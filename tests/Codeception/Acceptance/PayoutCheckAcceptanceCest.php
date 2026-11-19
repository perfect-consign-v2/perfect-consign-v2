<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Acceptance;

use PerfectConsign\Tests\Codeception\ProbeHarness;
use PerfectConsign\Tests\Codeception\Support\AcceptanceTester;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\SingleVendorCheckPrint;

/**
 * Chrome opens print checks for a paid vendor and the sales and rent sheet.
 * Staying on a check-number form is a failure.
 */
final class PayoutCheckAcceptanceCest
{
    public function ownerPrintsASingleVendorCheckAndTheSalesSheet(AcceptanceTester $I): void
    {
        $client = ProbeHarness::require()->client();
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $paid = SingleVendorCheckPrint::payOneVendor($client, ProbeMall::SLUG);
        $slug = ProbeMall::SLUG;
        $I->signInAsOwner();
        $I->amOnPage('/' . $slug . '/payouts/checks?run=' . $paid['runId']);
        $I->see('Pay to the order of');
        $I->see('Print sales and rent');
        $I->see('The check stock already has the company name and the check number.');
        $I->dontSee('First check number');
        $I->dontSee('name="starting_check"');
        $I->dontSee('class="check-mall"');
        $I->dontSee('class="check-number"');
        $I->click('a.print-sheet');
        $I->see('what they sold and the check');
        $I->see($paid['description']);
        $I->see('Store fee');
    }
}
