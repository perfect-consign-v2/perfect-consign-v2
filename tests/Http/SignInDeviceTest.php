<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PDO;
use PerfectConsign\Feature\Dev\LocalAdmin;
use PerfectConsign\Tests\Support\AdminDoor;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

/**
 * A browser that signed in before keeps working while someone else trips the
 * account-wide sign-in limit, the owner sees and removes it on the Password
 * page, and Admin, Sign-ins lists the attempts.
 */
final class SignInDeviceTest extends WebTestCase
{
    private const SLUG = ProbeMall::SLUG;

    private const ADMIN_EMAIL = 'sign-in-device-admin@example.test';

    private const ADMIN_PASSWORD = 'sign-in-device-admin-1';

    public function testALockedAccountStillSignsInFromATrustedBrowser(): void
    {
        $shop = self::client();
        self::assertSees("Today's sales", $shop->signInByEmail(ProbeMall::EMAIL, ProbeMall::PASSWORD));
        $shop->post('/' . self::SLUG . '/logout', []);
        try {
            $this->hammer();
            $stranger = self::client();
            self::assertSees('Too many sign-in attempts', $stranger->signInByEmail(ProbeMall::EMAIL, ProbeMall::PASSWORD));

            $back = $shop->signInByEmail(ProbeMall::EMAIL, ProbeMall::PASSWORD);
            self::assertSees("Today's sales", $back);

            $page = $shop->get('/' . self::SLUG . '/password');
            self::assertSees('Browsers you signed in from', $page);
            self::assertSees('This browser', $page);
            self::assertSame(1, preg_match('/name="device_id" value="([0-9a-f-]{36})"/', $page->body, $match));
            $removed = $shop->post('/' . self::SLUG . '/password/devices/remove', ['device_id' => $match[1]]);
            self::assertSees('That browser was removed.', $removed);
            self::assertStringNotContainsString('This browser', $removed->body);
            $again = $shop->post('/' . self::SLUG . '/password/devices/remove', ['device_id' => $match[1]]);
            self::assertSees('That browser is not on your list any more.', $again);

            $shop->post('/' . self::SLUG . '/logout', []);
            $this->hammer();
            self::assertSees('Too many sign-in attempts', $shop->signInByEmail(ProbeMall::EMAIL, ProbeMall::PASSWORD));
        } finally {
            ProbeMall::forgetSignIns(ProbeMall::USERNAME);
            $this->forgetAlerts();
        }
    }

    public function testAdminSignInsListsAndFiltersTheAttempts(): void
    {
        $nobody = 'sign-in-device-' . bin2hex(random_bytes(4)) . '@example.test';
        $client = self::client();
        try {
            self::assertSees('That email or password is wrong.', $client->signInByEmail($nobody, 'wrong-password'));
            $pdo = self::database();
            $hash = password_hash(self::ADMIN_PASSWORD, PASSWORD_DEFAULT);
            $pdo->prepare(
                'INSERT INTO platform.admins (id, email, password_hash) VALUES (uuidv7(), ?, ?)
                 ON CONFLICT (email) DO UPDATE SET password_hash = EXCLUDED.password_hash'
            )->execute([self::ADMIN_EMAIL, $hash]);
            AdminDoor::plant($pdo, self::ADMIN_EMAIL);
            $admin = self::client();
            self::assertSees('Tenants', AdminDoor::signIn($admin, self::ADMIN_EMAIL, self::ADMIN_PASSWORD));
            $all = $admin->get(AdminDoor::path('/sign-ins'));
            self::assertSees('Sign-ins', $all);
            $filtered = $admin->get(AdminDoor::path('/sign-ins') . '?email=' . rawurlencode($nobody) . '&outcome=unknown_email');
            self::assertSees($nobody, $filtered);
            self::assertSees('No such account', $filtered);
            self::assertSees('All sign-ins', $filtered);
            $none = $admin->get(AdminDoor::path('/sign-ins') . '?email=' . rawurlencode($nobody) . '&outcome=ok');
            self::assertSees('No sign-ins for that filter.', $none);
        } finally {
            ProbeMall::forgetEmailSignIns($nobody);
        }
    }

    private function hammer(): void
    {
        $pdo = self::database();
        $insert = $pdo->prepare(
            "INSERT INTO platform.sign_in_attempts (door, account_key, email_tried, tenant_id, username, client_address, user_agent, outcome, trusted_device)
             SELECT 'store', 'store:' || id::text || ':' || ?, ?, id, ?, '192.0.2.' || ?::text, 'bot', 'wrong_password', false
             FROM platform.tenants WHERE slug = ?"
        );
        for ($n = 1; $n <= 20; $n++) {
            $insert->execute([ProbeMall::USERNAME, ProbeMall::EMAIL, ProbeMall::USERNAME, (string) $n, self::SLUG]);
        }
    }

    private function forgetAlerts(): void
    {
        $pdo = self::database();
        $pdo->exec(
            "DELETE FROM platform.jobs WHERE kind = 'outbound-mail'
             AND payload ->> 'mail' IN (SELECT id::text FROM platform.mail_outbox WHERE purpose = 'sign-in-alert')"
        );
        $pdo->exec("DELETE FROM platform.mail_outbox WHERE purpose = 'sign-in-alert'");
        $pdo->prepare('DELETE FROM platform.sign_in_devices WHERE username = ? AND tenant_id = (SELECT id FROM platform.tenants WHERE slug = ?)')
            ->execute([ProbeMall::USERNAME, self::SLUG]);
    }

    private static function database(): PDO
    {
        return LocalAdmin::open()->pdo();
    }
}
