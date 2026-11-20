<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PDO;
use PerfectConsign\Feature\Dev\LocalAdmin;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Tests\Support\AdminDoor;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class ActivityLogTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private const ADMIN_EMAIL = 'platform-admin@example.test';

    private const ADMIN_PASSWORD = 'admin-pass-1';

    private FormClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $signedIn = $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
    }

    public function testTheSignedInAccountIsNamedOnTheActivityPage(): void
    {
        $name = 'Probe Mercantile ' . bin2hex(random_bytes(3));
        $saved = false;
        try {
            $page = $this->client->post('/' . self::SLUG . '/company', [
                'store_name' => $name,
                'street' => '1 Main',
                'street2' => '',
                'city' => 'Springfield',
                'state' => 'OR',
                'postal' => '97477',
                'phone' => '541-555-0100',
                'email' => 'owner@store.test',
            ]);
            self::assertSees('Company saved.', $page);
            $saved = true;
            $owner = $this->owner();
            $company = $this->latest('settings.edit');
            self::assertSame($owner['id'], $company['account_id']);
            self::assertSame('owner', $company['account_kind']);
            self::assertSame(ProbeMall::USERNAME, $company['username']);
            self::assertSame('owner', $company['role']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $company['request_id']);

            $username = 'c' . bin2hex(random_bytes(4));
            $added = $this->client->post('/' . self::SLUG . '/staff', [
                'role' => 'cashier',
                'display_name' => 'Activity Cashier',
                'username' => $username,
                'password' => 'cashier-pass',
                'email' => $username . '@example.com',
            ]);
            self::assertSees('Cashier added.', $added);
            $staff = $this->latest('staff.create');
            self::assertSame($owner['id'], $staff['account_id']);
            self::assertSame('owner', $staff['account_kind']);
            self::assertSame(ProbeMall::USERNAME, $staff['username']);
            self::assertSame('owner', $staff['role']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $staff['request_id']);
            self::assertNotSame($company['request_id'], $staff['request_id']);

            $activity = $this->client->get('/' . self::SLUG . '/activity');
            self::assertSees('Activity', $activity);
            self::assertSees(ProbeMall::USERNAME, $activity);
            self::assertSees('settings.edit', $activity);
            $unknown = $this->client->get('/' . self::SLUG . '/activity?extra=1');
            self::assertSees('That filter is not on this page.', $unknown);
            $csv = $this->client->get('/' . self::SLUG . '/activity.csv?extra=1');
            self::assertSees('That filter is not on this page.', $csv);
            self::assertStringNotContainsString('username,name', $csv->body);

            $cashier = self::client();
            $cashier->signIn(self::SLUG, $username, 'cashier-pass');
            $refused = $cashier->get('/' . self::SLUG . '/activity');
            self::assertSame(404, $refused->status);
            $changed = $cashier->post('/' . self::SLUG . '/password', [
                'current_password' => 'cashier-pass',
                'password' => 'cashier-pass-2',
                'confirm' => 'cashier-pass-2',
            ]);
            self::assertSees('Password updated.', $changed);
            $person = $this->staff($username);
            $password = $this->latest('password.change');
            self::assertSame($person, $password['account_id']);
            self::assertSame('staff', $password['account_kind']);
            self::assertSame($username, $password['username']);
            self::assertSame('cashier', $password['role']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $password['request_id']);
        } finally {
            if ($saved) {
                $this->client->signIn(self::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
                $this->client->post('/' . self::SLUG . '/company', [
                    'store_name' => ProbeMall::DISPLAY_NAME,
                    'street' => '',
                    'street2' => '',
                    'city' => '',
                    'state' => '',
                    'postal' => '',
                    'phone' => '',
                    'email' => '',
                ]);
            }
        }
    }

    public function testEnterAsNamesTheOperatorOnTheStoreRow(): void
    {
        $pdo = LocalAdmin::open()->pdo();
        $hash = (new PasswordChoice())->hash(self::ADMIN_PASSWORD);
        $pdo->prepare(
            'UPDATE platform.admins
             SET email = replace(id::text, \'-\', \'\') || \'@duplicate.test\'
             WHERE lower(email) = lower(?) AND email <> ?'
        )->execute([self::ADMIN_EMAIL, self::ADMIN_EMAIL]);
        $pdo->prepare(
            'INSERT INTO platform.admins (id, email, password_hash)
             VALUES (uuidv7(), ?, ?)
             ON CONFLICT (email) DO UPDATE
                SET password_hash = EXCLUDED.password_hash'
        )->execute([self::ADMIN_EMAIL, $hash]);
        AdminDoor::plant($pdo, self::ADMIN_EMAIL);
        $admin = $pdo->prepare('SELECT id::text FROM platform.admins WHERE lower(email) = lower(?)');
        $admin->execute([self::ADMIN_EMAIL]);
        $adminIds = $admin->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(1, $adminIds);
        $adminId = $adminIds[0];
        self::assertIsString($adminId);
        $operator = self::client();
        $signedIn = AdminDoor::signIn($operator, self::ADMIN_EMAIL, self::ADMIN_PASSWORD);
        self::assertSees('Tenants', $signedIn);
        $ownerId = $this->match(
            $signedIn->body,
            '#<code>' . self::SLUG . '</code>(?:(?!</tr>).)*name="owner_id" value="([0-9a-fA-F-]{36})">\s*'
                . '<button class="btn btn-sm" type="submit">Enter as ' . preg_quote(ProbeMall::USERNAME, '#') . '</button>#s',
        );
        $entered = $operator->post(AdminDoor::path('/tenants/') . self::SLUG . '/enter', [
            'role' => 'owner',
            'owner_id' => $ownerId,
        ]);
        self::assertSame(200, $entered->status);
        $row = $this->latest('platform.enter');
        self::assertSame($this->owner()['id'], $row['account_id']);
        self::assertSame('owner', $row['account_kind']);
        self::assertSame(ProbeMall::USERNAME, $row['username']);
        self::assertSame($adminId, $row['entered_by_admin_id']);
        self::assertSame(self::ADMIN_EMAIL, $row['entered_by_email']);
    }

    /**
     * @return array{id: string}
     */
    private function owner(): array
    {
        $pdo = $this->platform();
        $statement = $pdo->prepare(
            'SELECT owners.id::text
             FROM platform.owners AS owners
             JOIN platform.tenants AS tenants ON tenants.id = owners.tenant_id
             WHERE tenants.slug = ? AND owners.username = ?'
        );
        $statement->execute([self::SLUG, ProbeMall::USERNAME]);
        $id = $statement->fetchColumn();
        if (!is_string($id)) {
            self::fail('the probe owner is missing');
        }

        return ['id' => $id];
    }

    private function staff(string $username): string
    {
        $pdo = $this->tenant();
        $statement = $pdo->prepare('SELECT id::text FROM staff WHERE username = ?');
        $statement->execute([$username]);
        $id = $statement->fetchColumn();
        if (!is_string($id)) {
            self::fail('the cashier is missing');
        }

        return $id;
    }

    /**
     * @return array{
     *     account_id: string,
     *     account_kind: string,
     *     username: string,
     *     role: string,
     *     request_id: string,
     *     entered_by_admin_id: string,
     *     entered_by_email: string
     * }
     */
    private function latest(string $action): array
    {
        $pdo = $this->tenant();
        $statement = $pdo->prepare(
            'SELECT account_id::text AS account_id, account_kind, username, role, request_id,
                    entered_by_admin_id::text AS entered_by_admin_id, entered_by_email
             FROM audit_events
             WHERE action = ?
             ORDER BY at DESC
             LIMIT 1'
        );
        $statement->execute([$action]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            self::fail($action . ' was not stored');
        }
        foreach (['account_id', 'account_kind', 'username', 'role', 'request_id'] as $key) {
            if (!is_string($row[$key] ?? null) || $row[$key] === '') {
                self::fail($action . ' is missing ' . $key);
            }
        }

        return [
            'account_id' => $row['account_id'],
            'account_kind' => $row['account_kind'],
            'username' => $row['username'],
            'role' => $row['role'],
            'request_id' => $row['request_id'],
            'entered_by_admin_id' => is_string($row['entered_by_admin_id'] ?? null) ? $row['entered_by_admin_id'] : '',
            'entered_by_email' => is_string($row['entered_by_email'] ?? null) ? $row['entered_by_email'] : '',
        ];
    }

    private function tenant(): PDO
    {
        $pdo = $this->platform();
        $listed = $pdo->prepare('SELECT schema_name FROM platform.tenants WHERE slug = ?');
        $listed->execute([self::SLUG]);
        $schema = $listed->fetchColumn();
        if (!is_string($schema) || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            self::fail('webprobe schema is missing');
        }
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');

        return $pdo;
    }

    private function platform(): PDO
    {
        return LocalAdmin::open()->pdo();
    }

    private function match(string $body, string $pattern): string
    {
        if (preg_match($pattern, $body, $found) !== 1) {
            self::fail('the owner id was not on the tenant list');
        }

        return $found[1];
    }
}
