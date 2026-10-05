<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class ReviewCoverageTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private FormClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $signedIn = $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
    }

    public function testThePayoutCloseAndLayawayPagesOpen(): void
    {
        $payout = $this->client->get('/' . self::SLUG . '/payout');
        self::assertLessThan(500, $payout->status);
        $close = $this->client->get('/' . self::SLUG . '/close');
        self::assertSees('Year-end close', $close);
        $layaway = $this->client->get('/' . self::SLUG . '/pos/layaway/01900000-0000-7000-8000-000000000099');
        self::assertLessThan(500, $layaway->status);
    }

    public function testABadFormTokenRendersInTheLayout(): void
    {
        $expired = $this->client->postUnsigned('/' . self::SLUG . '/logout', ['csrf' => 'not-the-token']);
        self::assertSame(400, $expired->status);
        self::assertSees('The form expired', $expired);
        self::assertStringContainsString('<html', $expired->body);
    }

    public function testFiveBadPasswordsLockTheUsername(): void
    {
        $client = self::client();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $refused = $client->signIn(self::SLUG, 'nobody', 'wrong-password');
            self::assertSees('That username or password is wrong.', $refused);
        }
        $locked = $client->signIn(self::SLUG, 'nobody', 'wrong-password');
        self::assertSees('Too many sign-in attempts', $locked);
    }
}
