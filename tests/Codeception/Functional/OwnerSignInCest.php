<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Functional;

use PerfectConsign\Tests\Codeception\Support\FunctionalTester;
use PerfectConsign\Tests\Support\ProbeMall;

final class OwnerSignInCest
{
    public function ownerSignsInAndSeesDashboard(FunctionalTester $I): void
    {
        $I->wantTo('sign in as the webprobe owner and land on the book');
        $I->loginAsOwner();
        $I->see("Today's sales");
        $I->seeInCurrentUrl('/' . ProbeMall::SLUG . '/dashboard');
    }

    public function signedInOwnerKeyPagesLoad(FunctionalTester $I): void
    {
        $I->loginAsOwner();
        $slug = ProbeMall::SLUG;
        foreach ([
            '/' . $slug . '/dashboard',
            '/' . $slug . '/pos',
            '/' . $slug . '/inventory',
            '/' . $slug . '/parties',
            '/' . $slug . '/booths',
            '/' . $slug . '/journals',
            '/' . $slug . '/settings',
            '/' . $slug . '/staff',
            '/' . $slug . '/rent',
            '/' . $slug . '/payout',
        ] as $path) {
            $I->amOnPage($path);
            $I->dontSee('This address is not a page in');
            $I->dontSee('Fatal error');
            $I->dontSee('Uncaught');
        }
    }
}
