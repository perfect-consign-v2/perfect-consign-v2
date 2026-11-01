<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class StaffLifecycleCoverageTest extends WebTestCase
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

    public function testSuspendBlocksSignInAndRoleChangeWorks(): void
    {
        $username = 'l' . bin2hex(random_bytes(4));
        $email = $username . '@example.com';
        $added = $this->client->post('/' . self::SLUG . '/staff', [
            'role' => 'cashier',
            'display_name' => 'Life Cycle',
            'username' => $username,
            'password' => 'cashier-pass',
            'email' => $email,
        ]);
        self::assertSees('Cashier added.', $added);
        $id = $this->match(
            $added->body,
            '#\(' . preg_quote($username, '#') . '\).*?/staff/([0-9a-fA-F-]{36})/password#s'
        );

        $role = $this->client->post('/' . self::SLUG . '/staff/' . $id . '/role', [
            'role' => 'manager',
        ]);
        self::assertSees('Role saved.', $role);
        self::assertSees('Managers', $role);
        self::assertSees($username, $role);

        $suspended = $this->client->post('/' . self::SLUG . '/staff/' . $id . '/suspend', []);
        self::assertSees('Person suspended.', $suspended);
        self::assertSees('Suspended', $suspended);

        $this->client->post('/' . self::SLUG . '/logout', []);
        $blocked = $this->client->signInByEmail($email, 'cashier-pass');
        self::assertTrue(
            $blocked->status !== 200
            || !str_contains($blocked->body, 'Sell')
            || str_contains($blocked->body, 'Sign in'),
            'suspended staff must not open the mall'
        );

        $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $this->client->get('/' . self::SLUG . '/staff');
        $restored = $this->client->post('/' . self::SLUG . '/staff/' . $id . '/restore', []);
        self::assertSees('Person restored.', $restored);

        $this->client->post('/' . self::SLUG . '/logout', []);
        $ok = $this->client->signInByEmail($email, 'cashier-pass');
        self::assertSame(200, $ok->status);
        self::assertSees('Sell', $ok);
        self::assertSees('Manager', $ok);
        $this->client->post('/' . self::SLUG . '/logout', []);
    }

    public function testOwnerSuspendRefusesLastActive(): void
    {
        $username = 's' . bin2hex(random_bytes(4));
        $email = $username . '@example.com';
        $added = $this->client->post('/' . self::SLUG . '/staff', [
            'role' => 'owner',
            'username' => $username,
            'password' => 'owner-pass1',
            'email' => $email,
        ]);
        self::assertSees('Owner added.', $added);
        $partnerId = $this->match(
            $added->body,
            '#\b' . preg_quote($username, '#') . '\b.*?/staff/owners/([0-9a-fA-F-]{36})/password#s'
        );
        $selfId = $this->signedInOwnerId($added->body);
        $this->suspendOtherActiveOwners($added->body, $selfId, $partnerId);

        $suspended = $this->client->post('/' . self::SLUG . '/staff/owners/' . $partnerId . '/suspend', []);
        self::assertSees('Owner suspended.', $suspended);

        $refused = $this->client->post('/' . self::SLUG . '/staff/owners/' . $selfId . '/suspend', []);
        self::assertSees('Keep at least one active owner on the mall.', $refused);

        $this->assertEnterAsRefusesSuspendedOwner($partnerId);

        $restored = $this->client->post('/' . self::SLUG . '/staff/owners/' . $partnerId . '/restore', []);
        self::assertSees('Owner restored.', $restored);
    }

    private function assertEnterAsRefusesSuspendedOwner(string $ownerId): void
    {
        $this->seedPlatformAdmin();
        $admin = self::client();
        $admin->get('/admin/login');
        $admin->post('/admin/login', [
            'email' => 'platform-admin@example.test',
            'password' => 'admin-pass-1',
        ]);
        $blocked = $admin->post('/admin/tenants/' . self::SLUG . '/enter', [
            'role' => 'owner',
            'owner_id' => $ownerId,
        ]);
        self::assertSees('That owner is suspended.', $blocked);
    }

    private function seedPlatformAdmin(): void
    {
        $host = getenv('STALL_DB_HOST') ?: '127.0.0.1';
        $port = getenv('STALL_DB_PORT') ?: '5436';
        $name = getenv('STALL_DB_NAME') ?: 'consign';
        $user = getenv('STALL_DB_USER') ?: 'stall';
        $password = getenv('STALL_DB_PASSWORD') ?: 'stall';
        $pdo = new \PDO(
            'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
            $user,
            $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $hash = (new \PerfectConsign\Feature\Password\PasswordChoice())->hash('admin-pass-1');
        $statement = $pdo->prepare(
            'INSERT INTO platform.admins (id, email, password_hash)
             VALUES (uuidv7(), ?, ?)
             ON CONFLICT (email) DO UPDATE
             SET password_hash = EXCLUDED.password_hash,
                 credentials_version = platform.admins.credentials_version + 1'
        );
        $statement->execute(['platform-admin@example.test', $hash]);
    }

    private function suspendOtherActiveOwners(string $html, string $selfId, string $keepId): void
    {
        preg_match_all('#/staff/owners/([0-9a-fA-F-]{36})/suspend#', $html, $matches);
        foreach ($matches[1] as $ownerId) {
            if ($ownerId === $selfId || $ownerId === $keepId) {
                continue;
            }
            $done = $this->client->post('/' . self::SLUG . '/staff/owners/' . $ownerId . '/suspend', []);
            self::assertSees('Owner suspended.', $done);
        }
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
