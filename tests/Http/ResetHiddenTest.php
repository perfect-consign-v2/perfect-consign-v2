<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class ResetHiddenTest extends WebTestCase
{
    public function testTheProbeMallHidesTestDataReset(): void
    {
        $client = self::client();
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $page = $client->post('/' . ProbeMall::SLUG . '/reset-test-data', []);
        self::assertSame(404, $page->status);
    }
}
