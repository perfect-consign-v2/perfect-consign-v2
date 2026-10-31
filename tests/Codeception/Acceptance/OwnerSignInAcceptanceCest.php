<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Acceptance;

use PerfectConsign\Tests\Codeception\Support\AcceptanceTester;
use PerfectConsign\Tests\Support\ProbeMall;

final class OwnerSignInAcceptanceCest
{
    public function ownerSignsInInChromeAndSeesDashboard(AcceptanceTester $I): void
    {
        $I->wantTo('sign in through Chrome and see today\'s sales');
        $I->signInAsOwner();
        $I->see("Today's sales");
        $I->seeInCurrentUrl('/' . ProbeMall::SLUG . '/dashboard');
    }

    public function ownerOpensPosFromDashboardNav(AcceptanceTester $I): void
    {
        $I->signInAsOwner();
        $I->amOnPage('/' . ProbeMall::SLUG . '/dashboard');
        $I->click('a[href="/' . ProbeMall::SLUG . '/pos"]');
        $I->dontSee('This address is not a page in');
        // Sale sends the owner to Registers when this station has no open drawer.
        $path = $I->grabCurrentPath();
        $I->assertTrue(
            str_contains($path, '/' . ProbeMall::SLUG . '/pos')
            || str_contains($path, '/' . ProbeMall::SLUG . '/registers'),
            'Sale nav left the owner on an unexpected path: ' . $path
        );
    }
}
