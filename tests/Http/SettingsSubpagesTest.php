<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class SettingsSubpagesTest extends WebTestCase
{
    private FormClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $this->client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
    }

    public function testSettingsHubRedirectsAndSubpagesOpen(): void
    {
        $slug = ProbeMall::SLUG;
        $hub = $this->client->get('/' . $slug . '/settings');
        self::assertSees('Settings', $hub);
        self::assertSees('aria-current="page"', $hub);
        self::assertSees('/' . $slug . '/settings/money', $hub);
        foreach (['money', 'building', 'receipt', 'backup'] as $part) {
            $page = $this->client->get('/' . $slug . '/settings/' . $part);
            self::assertSame(200, $page->status);
            self::assertSees('Settings', $page);
            self::assertSees('href="/' . $slug . '/settings/' . $part . '"', $page);
        }
        $money = $this->client->post('/' . $slug . '/settings/money', [
            'settings_part' => 'money',
            'default_store_take' => '10',
            'tax_rate' => '8.5',
            'cash_round_minor' => '0',
            'operating_mode' => 'both',
            'booth_rentals_enabled' => '1',
            'rent_cycle_day' => '1',
            'late_fee_grace_days' => '0',
        ]);
        self::assertSees('Money saved.', $money);
        self::assertSees('/' . $slug . '/settings/money', $money);

        $legacy = $this->client->post('/' . $slug . '/settings', [
            'settings_part' => 'money',
            'default_store_take' => '10',
            'tax_rate' => '8.5',
            'operating_mode' => 'both',
        ]);
        self::assertSees('Save Money, Building, or Receipt from that Settings page.', $legacy);
        self::assertSees('/' . $slug . '/settings/money', $legacy);
    }
}
