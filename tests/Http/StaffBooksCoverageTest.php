<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class StaffBooksCoverageTest extends WebTestCase
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

    public function testABooksLoginIsAddedAndABadPasswordIsRefused(): void
    {
        $username = 'b' . bin2hex(random_bytes(4));
        $refused = $this->client->post('/' . self::SLUG . '/staff/books', [
            'display_name' => 'Books',
            'username' => $username,
            'password' => 'short',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('at least 8 characters', $refused);
        $added = $this->client->post('/' . self::SLUG . '/staff/books', [
            'display_name' => 'Books',
            'username' => $username,
            'password' => 'books-pass',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('Books login added.', $added);
        $again = $this->client->post('/' . self::SLUG . '/staff/books', [
            'display_name' => 'Books',
            'username' => $username,
            'password' => 'books-pass',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('That sign-in name is already used.', $again);
        $id = $this->match($added->body, '#/staff/([0-9a-fA-F-]{36})/books-password#');
        $saved = $this->client->post('/' . self::SLUG . '/staff/' . $id . '/books-password', [
            'password' => 'books-next',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('Books password saved.', $saved);
        $missing = $this->client->post('/' . self::SLUG . '/staff/00000000-0000-7000-8000-000000000099/books-password', [
            'password' => 'books-next',
            'email' => $username . '@example.com',
        ]);
        self::assertSees('Choose a books login.', $missing);

        $signedOut = $this->client->post('/' . self::SLUG . '/logout', []);
        self::assertSees('Sign out', $signedOut);
        $left = $this->client->post('/' . self::SLUG . '/logout/confirm', []);
        self::assertSees('Sign in', $left);
    }

    private function match(string $html, string $pattern): string
    {
        self::assertSame(1, preg_match($pattern, $html, $match), $pattern);

        return $match[1];
    }
}
