<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class StaffManagerCoverageTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private FormClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $this->client->get('/' . self::SLUG . '/staff');
    }

    public function testAManagerIsAddedAndReplaced(): void
    {
        $page = $this->client->get('/' . self::SLUG . '/staff');
        self::assertSees('People', $page);
        self::assertSees('Add a manager', $page);
        $username = 'm' . bin2hex(random_bytes(4));
        $refused = $this->client->post('/' . self::SLUG . '/staff/managers', [
            'display_name' => 'Manager',
            'username' => $username,
            'password' => 'short',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('at least 8 characters', $refused);
        $added = $this->client->post('/' . self::SLUG . '/staff/managers', [
            'display_name' => 'Manager',
            'username' => $username,
            'password' => 'manager-pass',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('Manager added.', $added);
        $id = $this->match(
            $added->body,
            '#\(' . preg_quote($username, '#') . '\).*?/staff/([0-9a-fA-F-]{36})/manager-password#s'
        );
        $saved = $this->client->post('/' . self::SLUG . '/staff/' . $id . '/manager-password', [
            'password' => 'manager-next',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('Manager password saved.', $saved);
        $missing = $this->client->post('/' . self::SLUG . '/staff/00000000-0000-7000-8000-000000000099/manager-password', [
            'password' => 'manager-next',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('Choose a manager.', $missing);

        $this->client->post('/' . self::SLUG . '/logout', []);
        $asManager = $this->client->signInByEmail($username . '@example.com', 'manager-next');
        self::assertSame(200, $asManager->status);
        self::assertSees('Manager', $asManager);
        self::assertSees('Sell', $asManager);
        self::assertSees('View payouts', $asManager);
        self::assertStringNotContainsString('>People<', $asManager->body);
        self::assertStringNotContainsString('>Settings<', $asManager->body);
    }

    private function match(string $html, string $pattern): string
    {
        self::assertSame(1, preg_match($pattern, $html, $match), $pattern);

        return $match[1];
    }
}
