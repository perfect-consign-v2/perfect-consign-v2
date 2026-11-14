<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Acceptance;

use PerfectConsign\Feature\Password\ResetNotice;
use PerfectConsign\Tests\Codeception\Support\AcceptanceTester;
use PerfectConsign\Tests\Support\ProbeMall;

/**
 * The same action and response, driven through Chrome.
 */
final class UserActionAcceptanceCest
{
    public function aWrongEmailAndABlankEmailStayOnSignIn(AcceptanceTester $I): void
    {
        $I->amOnPage('/');
        $I->submitPageForm('/', [
            'email' => 'nobody@example.test',
            'password' => 'not-the-password',
        ]);
        $I->see('That email or password is wrong.');
        $I->submitPageForm('/', [
            'email' => '',
            'password' => '',
        ]);
        $I->see('That email or password is wrong.');
    }

    public function aMissingEmailAndTheOwnerEmailGetTheSameResetSentence(AcceptanceTester $I): void
    {
        $I->amOnPage('/forgot');
        $I->submitPageForm('/forgot', ['email' => 'nobody@example.test']);
        $I->see(ResetNotice::SENT);
        $I->submitPageForm('/forgot', ['email' => ProbeMall::EMAIL]);
        $I->see(ResetNotice::SENT);
    }

    public function aBlankVendorNameStaysOnTheFormAndANamedVendorIsCreated(AcceptanceTester $I): void
    {
        $I->signInAsOwner();
        $slug = ProbeMall::SLUG;
        $I->amOnPage('/' . $slug . '/vendors/new');
        $I->submitPageForm('/' . $slug . '/vendors', [
            'code' => 'blank',
            'first_name' => '',
            'last_name' => 'Lane',
        ]);
        $I->see('a first name is required');
        $code = 'c' . bin2hex(random_bytes(3));
        $I->submitPageForm('/' . $slug . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
        ]);
        $I->see('Vendor created.');
        $I->see($code);
    }

    public function aCashierBooksAndVendorCannotOpenOwnerPages(AcceptanceTester $I): void
    {
        $seed = $I->probeSeed();
        $slug = ProbeMall::SLUG;
        $I->signInAs($seed->cashierUsername, $seed->cashierPassword);
        $I->amOnPage('/' . $slug . '/settings');
        $I->see('This address is not a page in');
        $I->signInAs($seed->booksUsername, $seed->booksPassword);
        $I->amOnPage('/' . $slug . '/staff');
        $I->see('This address is not a page in');
        $I->signInAs($seed->vendorUsername, $seed->vendorPassword);
        $I->amOnPage('/' . $slug . '/payouts');
        $I->see('This address is not a page in');
        $I->amOnPage('/' . $slug . '/pos');
        $I->see('This address is not a page in');
    }
}
