<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class StaffOwnersCoverageTest extends WebTestCase
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

    public function testACoOwnerIsAddedResetAndRemovable(): void
    {
        $username = 'o' . bin2hex(random_bytes(4));
        $email = $username . '@example.com';
        $refused = $this->client->post('/' . self::SLUG . '/staff', [
            'role' => 'owner',
            'username' => $username,
            'password' => 'short',
            'email' => $email,
        ]);
        self::assertSees('at least 8 characters', $refused);
        $added = $this->client->post('/' . self::SLUG . '/staff', [
            'role' => 'owner',
            'username' => $username,
            'password' => 'owner-pass1',
            'email' => $email,
        ]);
        self::assertSees('Owner added.', $added);
        $dup = $this->client->post('/' . self::SLUG . '/staff', [
            'role' => 'owner',
            'username' => $username,
            'password' => 'owner-pass1',
            'email' => 'other-' . $email,
        ]);
        self::assertSees('That sign-in name is already used.', $dup);
        $partnerId = $this->match(
            $added->body,
            '#\b' . preg_quote($username, '#') . '\b.*?/staff/owners/([0-9a-fA-F-]{36})/password#s'
        );
        $saved = $this->client->post('/' . self::SLUG . '/staff/owners/' . $partnerId . '/password', [
            'password' => 'owner-next1',
            'email' => $email,
        ]);
        self::assertSees('Owner sign-in saved.', $saved);

        $selfId = $this->signedInOwnerId($saved->body);
        $selfRemove = $this->client->post('/' . self::SLUG . '/staff/owners/' . $selfId . '/remove', []);
        self::assertSees('You cannot remove your own owner sign-in.', $selfRemove);

        $removed = $this->client->post('/' . self::SLUG . '/staff/owners/' . $partnerId . '/remove', []);
        self::assertSees('Owner removed.', $removed);

        $againUser = 'p' . bin2hex(random_bytes(4));
        $againEmail = $againUser . '@example.com';
        $again = $this->client->post('/' . self::SLUG . '/staff', [
            'role' => 'owner',
            'username' => $againUser,
            'password' => 'partner-pass',
            'email' => $againEmail,
        ]);
        self::assertSees('Owner added.', $again);
        $this->client->post('/' . self::SLUG . '/logout', []);
        $signed = $this->client->signInByEmail($againEmail, 'partner-pass');
        self::assertSame(200, $signed->status);
        self::assertSees('People', $signed);
    }

    private function signedInOwnerId(string $html): string
    {
        preg_match_all('#/staff/owners/([0-9a-fA-F-]{36})/password#', $html, $passwords);
        preg_match_all('#/staff/owners/([0-9a-fA-F-]{36})/remove#', $html, $removes);
        $removable = $removes[1] ?? [];
        foreach ($passwords[1] as $ownerId) {
            if (!in_array($ownerId, $removable, true)) {
                return $ownerId;
            }
        }
        self::fail('signed-in owner id not found');
    }

    private function match(string $html, string $pattern): string
    {
        self::assertSame(1, preg_match($pattern, $html, $match), $pattern);

        return $match[1];
    }
}
