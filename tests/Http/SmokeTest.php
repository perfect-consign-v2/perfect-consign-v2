<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('smoke')]
final class SmokeTest extends WebTestCase
{
    public function testTheRootPageNamesTheProduct(): void
    {
        $page = self::client()->get('/');

        self::assertSame(200, $page->status);
        self::assertSees('Perfect Consign', $page);
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
}
