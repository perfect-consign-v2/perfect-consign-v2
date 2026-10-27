<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('smoke')]
final class SmokeTest extends WebTestCase
{
    public function testTheRootPageShowsEmailSignIn(): void
    {
        $page = self::client()->get('/');

        self::assertSame(200, $page->status);
        self::assertSees('Perfect Consign', $page);
        self::assertSees('<h1>Sign in</h1>', $page);
        self::assertSees('name="email"', $page);
        self::assertSees('name="password"', $page);
        self::assertStringNotContainsString('Open your mall', $page->body);
        self::assertStringNotContainsString('name="mall"', $page->body);
    }

    public function testAWrongEmailIsRefused(): void
    {
        $page = self::client()->signInByEmail('nobody@webprobe.test', ProbeMall::PASSWORD);

        self::assertSame(200, $page->status);
        self::assertSees('That email or password is wrong.', $page);
    }

    public function testAWrongPasswordIsRefusedAtTheDoor(): void
    {
        $page = self::client()->signInByEmail(ProbeMall::EMAIL, 'wrong-password');

        self::assertSame(200, $page->status);
        self::assertSees('That email or password is wrong.', $page);
    }

    public function testTheOwnerSignsInWithEmail(): void
    {
        $page = self::client()->signInByEmail(ProbeMall::EMAIL, ProbeMall::PASSWORD);

        self::assertSame(200, $page->status);
        self::assertSees("Today's sales", $page);
    }

    public function testASignedInOwnerLeavesTheDoorForTheDashboard(): void
    {
        $client = self::client();
        $client->signInByEmail(ProbeMall::EMAIL, ProbeMall::PASSWORD);
        $page = $client->getWithoutRedirect('/');

        self::assertSame(303, $page->status);
        self::assertStringContainsString('/' . ProbeMall::SLUG . '/dashboard', $page->location);
    }

    public function testAMallHomeOffersSignIn(): void
    {
        $page = self::client()->get('/' . ProbeMall::SLUG);

        self::assertSame(200, $page->status);
        self::assertSees(ProbeMall::DISPLAY_NAME, $page);
        self::assertSees('Sign in', $page);
    }

    public function testTheSignInFormIsShown(): void
    {
        $page = self::client()->get('/' . ProbeMall::SLUG . '/login');

        self::assertSame(200, $page->status);
        self::assertSees('<h1>Sign in</h1>', $page);
        self::assertSees('name="password"', $page);
    }

    public function testAWrongPasswordIsRefused(): void
    {
        $page = self::client()->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, 'wrong-password');

        self::assertSame(200, $page->status);
        self::assertSees('That username or password is wrong.', $page);
    }

    public function testAnUnsignedRequestDoesNotOpenTheBook(): void
    {
        $page = self::client()->getWithoutRedirect('/' . ProbeMall::SLUG . '/book');

        self::assertSame(303, $page->status);
        self::assertStringContainsString('/' . ProbeMall::SLUG . '/login', $page->location);
    }

    public function testTheOwnerSignsInToTheBook(): void
    {
        $page = self::client()->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);

        self::assertSame(200, $page->status);
        self::assertSees("Today's sales", $page);
    }

    public function testAnUnknownAddressOffersNoMallLink(): void
    {
        $page = self::client()->get('/no-such-page');

        self::assertSame(404, $page->status);
        self::assertSees('This address is not in Perfect Consign.', $page);
        self::assertStringNotContainsString('sidebar', $page->body);
        self::assertStringContainsString('noindex', $page->body);
    }

    public function testAMissingMallPageOffersSignIn(): void
    {
        $page = self::client()->get('/' . ProbeMall::SLUG . '/r');

        self::assertSame(404, $page->status);
        self::assertSees('This address is not a page in ' . ProbeMall::DISPLAY_NAME . '.', $page);
        self::assertSees('/' . ProbeMall::SLUG . '/login', $page);
        self::assertStringNotContainsString('Go to the dashboard', $page->body);
    }

    public function testASignedInOwnerRecoversFromAMissingPage(): void
    {
        $client = self::client();
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $page = $client->get('/' . ProbeMall::SLUG . '/r');

        self::assertSame(404, $page->status);
        self::assertSees('Go to the dashboard', $page);
        self::assertSees('/' . ProbeMall::SLUG . '/dashboard', $page);
        self::assertSees('sidebar', $page);
        self::assertStringNotContainsString('name="q"', $page->body);
    }
}
