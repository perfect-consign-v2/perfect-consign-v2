<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class ReportGapsTest extends WebTestCase
{
    private FormClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $signedIn = $this->client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
    }

    public function testNamedReportGapsOpenFromTheMenu(): void
    {
        $slug = ProbeMall::SLUG;
        $menu = $this->client->get('/' . $slug . '/reports');
        self::assertSame(200, $menu->status);
        self::assertSees('Hourly sales', $menu);
        self::assertSees('Category sell-through', $menu);
        self::assertSees('Expiring items', $menu);
        self::assertSees('Donated items', $menu);

        $hourly = $this->client->get('/' . $slug . '/reports/sales?group=hour');
        self::assertSame(200, $hourly->status);
        self::assertSees('Sales', $hourly);

        $sell = $this->client->get('/' . $slug . '/reports/sell-through');
        self::assertSame(200, $sell->status);
        self::assertSees('Category sell-through', $sell);

        $expiring = $this->client->get('/' . $slug . '/reports/expiring');
        self::assertSame(200, $expiring->status);
        self::assertSees('Expiring items', $expiring);

        $donated = $this->client->get('/' . $slug . '/reports/donated');
        self::assertSame(200, $donated->status);
        self::assertSees('Donated items', $donated);

        self::assertSame(200, $this->client->get('/' . $slug . '/reports/expiring.csv')->status);
        self::assertSame(200, $this->client->get('/' . $slug . '/reports/donated.csv')->status);
        self::assertSame(200, $this->client->get('/' . $slug . '/reports/sell-through.csv')->status);
    }
}
