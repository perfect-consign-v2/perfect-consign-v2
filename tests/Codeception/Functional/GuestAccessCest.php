<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Functional;

use PerfectConsign\Tests\Codeception\Support\FunctionalTester;
use PerfectConsign\Tests\Support\ProbeMall;

final class GuestAccessCest
{
    public function rootShowsSignIn(FunctionalTester $I): void
    {
        $I->wantTo('see email sign-in on the public root');
        $I->amOnPage('/');
        $I->see('Perfect Consign');
        $I->see('Sign in');
        $I->seeElement('input[name="email"]');
        $I->seeElement('input[name="password"]');
        $I->dontSee('Open your mall');
    }

    public function dashboardRedirectsGuestToSignIn(FunctionalTester $I): void
    {
        $I->amOnPage('/' . ProbeMall::SLUG . '/dashboard');
        $I->seeInCurrentUrl('/');
        $I->see('Sign in');
    }

    public function posRedirectsGuestToSignIn(FunctionalTester $I): void
    {
        $I->amOnPage('/' . ProbeMall::SLUG . '/pos');
        $I->see('Sign in');
    }

    public function vendorPortalRedirectsGuestToSignIn(FunctionalTester $I): void
    {
        $I->amOnPage('/' . ProbeMall::SLUG . '/vendor');
        $I->see('Sign in');
    }

    public function wrongEmailIsRefused(FunctionalTester $I): void
    {
        $I->amOnPage('/');
        $I->submitForm('form[action="/"]', [
            'email' => 'nobody@webprobe.test',
            'password' => ProbeMall::PASSWORD,
        ]);
        $I->see('That email or password is wrong.');
    }

    public function wrongPasswordIsRefused(FunctionalTester $I): void
    {
        $I->amOnPage('/');
        $I->submitForm('form[action="/"]', [
            'email' => ProbeMall::EMAIL,
            'password' => 'wrong-password',
        ]);
        $I->see('That email or password is wrong.');
    }
}
