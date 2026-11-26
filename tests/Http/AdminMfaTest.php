<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Feature\Admin\Mfa\Base32;
use PerfectConsign\Feature\Admin\Mfa\Totp;
use PerfectConsign\Feature\Dev\LocalAdmin;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Tests\Support\AdminDoor;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\WebTestCase;
use PDO;

final class AdminMfaTest extends WebTestCase
{
    public function testEnrollmentShowsTheKeyAndStaysClosedUntilTheCodesAreSaved(): void
    {
        $email = 'mfa-enroll@example.test';
        $password = 'enroll-pass-1';
        $pdo = $this->ensureAdmin($email, $password);
        AdminDoor::clear($pdo, $email);
        $client = self::client();
        $client->get(AdminDoor::path('/login'));
        $enroll = $client->post(AdminDoor::path('/login'), [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertStringContainsString('Set up authenticator', $enroll->body);
        self::assertStringContainsString('PC2', $enroll->body);
        self::assertStringContainsString($email, $enroll->body);
        self::assertStringContainsString('<svg', $enroll->body);
        self::assertStringNotContainsString('otpauth://', $enroll->body);
        $secret = $this->secret($enroll->body);
        $confirmed = $client->post(AdminDoor::path('/mfa/enroll'), [
            'code' => Totp::codeForStep($secret, Totp::stepForTime(time())),
        ]);
        self::assertStringContainsString('Save recovery codes', $confirmed->body);
        self::assertStringContainsString('I saved these codes', $confirmed->body);
        preg_match_all('/[A-Z2-7]{5}-[A-Z2-7]{5}-[A-Z2-7]{5}-[A-Z2-7]{5}/', $confirmed->body, $codes);
        self::assertCount(10, $codes[0]);

        $closed = $client->getWithoutRedirect(AdminDoor::path('/tenants'));
        self::assertSame(303, $closed->status);
        self::assertStringContainsString(AdminDoor::path('/login'), $closed->location);

        $open = $client->post(AdminDoor::path('/mfa/codes'), []);
        self::assertStringContainsString('Tenants', $open->body);
    }

    public function testAPendingSignInCannotOpenTenantsAndAWrongCodeStaysOnTheForm(): void
    {
        $email = 'mfa-challenge@example.test';
        $password = 'challenge-pass-1';
        $pdo = $this->ensureAdmin($email, $password);
        AdminDoor::plant($pdo, $email);
        $client = self::client();
        $client->get(AdminDoor::path('/login'));
        $form = $client->post(AdminDoor::path('/login'), [
            'email' => $email,
            'password' => $password,
        ]);
        self::assertStringContainsString('Authenticator code', $form->body);

        $closed = $client->getWithoutRedirect(AdminDoor::path('/tenants'));
        self::assertSame(303, $closed->status);
        self::assertStringContainsString(AdminDoor::path('/login'), $closed->location);

        $empty = $client->post(AdminDoor::path('/mfa'), []);
        self::assertStringContainsString('Enter a code or a recovery code.', $empty->body);
        $both = $client->post(AdminDoor::path('/mfa'), [
            'code' => '123456',
            'recovery_code' => 'AAAAA-BBBBB-CCCCC-DDDDD',
        ]);
        self::assertStringContainsString('Enter a code or a recovery code.', $both->body);
        $real = Totp::codeForStep(AdminDoor::SECRET, Totp::stepForTime(time()));
        $wrong = $client->post(AdminDoor::path('/mfa'), [
            'code' => $real === '000000' ? '111111' : '000000',
        ]);
        self::assertStringContainsString('That code is wrong.', $wrong->body);
        self::assertStringNotContainsString('Tenants', $wrong->body);
    }

    public function testARecoveryCodeOpensTheDoorOnce(): void
    {
        $email = 'mfa-recover@example.test';
        $password = 'recover-pass-1';
        $pdo = $this->ensureAdmin($email, $password);
        AdminDoor::plant($pdo, $email);
        $code = AdminDoor::recoveryCode($pdo, $email);
        $client = self::client();
        $client->get(AdminDoor::path('/login'));
        $client->post(AdminDoor::path('/login'), [
            'email' => $email,
            'password' => $password,
        ]);
        $opened = $client->post(AdminDoor::path('/mfa'), ['recovery_code' => $code]);
        self::assertStringContainsString('Tenants', $opened->body);

        $again = self::client();
        $again->get(AdminDoor::path('/login'));
        $again->post(AdminDoor::path('/login'), [
            'email' => $email,
            'password' => $password,
        ]);
        $used = $again->post(AdminDoor::path('/mfa'), ['recovery_code' => $code]);
        self::assertStringContainsString('That recovery code is wrong.', $used->body);
        self::assertStringNotContainsString('>Tenants<', $used->body);
    }

    public function testASignedInAdminCanTurnTheAuthenticatorOffAndOn(): void
    {
        $email = 'mfa-switch@example.test';
        $password = 'switch-pass-1';
        $pdo = $this->ensureAdmin($email, $password);
        AdminDoor::plant($pdo, $email);
        $reset = $pdo->prepare("DELETE FROM platform.settings WHERE key = 'admin_mfa_enabled'");
        $reset->execute();
        $resetFailures = $pdo->prepare("DELETE FROM platform.sign_in_attempts WHERE door = 'admin' AND email_tried = ?");
        $resetFailures->execute([strtolower($email)]);
        $resetCodes = $pdo->prepare('DELETE FROM platform.admin_mfa_failures WHERE email = ?');
        $resetCodes->execute([$email]);
        try {
            $client = self::client();
            $closed = $client->getWithoutRedirect(AdminDoor::path('/authenticator'));
            self::assertSame(303, $closed->status);
            self::assertStringContainsString(AdminDoor::path('/login'), $closed->location);

            AdminDoor::signIn($client, $email, $password);
            $page = $client->get(AdminDoor::path('/authenticator'));
            self::assertStringContainsString('Authenticator is on. Sign-in asks for a code.', $page->body);
            self::assertStringContainsString('name="admin_mfa_enabled" value="1" checked', $page->body);
            self::assertStringContainsString('name="code"', $page->body);

            $missing = $client->post(AdminDoor::path('/authenticator'), [
                'current' => $password,
            ]);
            self::assertStringContainsString('Enter the authenticator code to turn it off.', $missing->body);
            self::assertStringContainsString('name="admin_mfa_enabled" value="1" checked', $missing->body);

            $step = Totp::stepForTime(time());
            $wrongPassword = $client->post(AdminDoor::path('/authenticator'), [
                'current' => 'not-the-password',
                'code' => Totp::codeForStep(AdminDoor::SECRET, $step),
            ]);
            self::assertStringContainsString('That password is wrong.', $wrongPassword->body);
            for ($attempt = 0; $attempt < 4; $attempt++) {
                $client->post(AdminDoor::path('/authenticator'), [
                    'current' => 'not-the-password',
                    'code' => '000000',
                ]);
            }
            $locked = $client->post(AdminDoor::path('/authenticator'), [
                'current' => $password,
                'code' => Totp::codeForStep(AdminDoor::SECRET, $step),
            ]);
            self::assertStringContainsString('Too many sign-in attempts. Try again in a few minutes.', $locked->body);
            $clearSignIn = $pdo->prepare("DELETE FROM platform.sign_in_attempts WHERE door = 'admin' AND email_tried = ?");
            $clearSignIn->execute([strtolower($email)]);

            $wrongCode = $client->post(AdminDoor::path('/authenticator'), [
                'current' => $password,
                'code' => Totp::codeForStep(AdminDoor::SECRET, $step) === '000000' ? '111111' : '000000',
            ]);
            self::assertStringContainsString('That code is wrong.', $wrongCode->body);
            self::assertStringContainsString('name="admin_mfa_enabled" value="1" checked', $wrongCode->body);

            $off = $client->post(AdminDoor::path('/authenticator'), [
                'current' => $password,
                'code' => $this->freshCode($pdo, $email),
            ]);
            self::assertStringContainsString('Authenticator is off. Sign-in is email and password.', $off->body);
            self::assertStringNotContainsString('name="admin_mfa_enabled" value="1" checked', $off->body);

            $passwordOnly = self::client();
            $passwordOnly->get(AdminDoor::path('/login'));
            $opened = $passwordOnly->post(AdminDoor::path('/login'), [
                'email' => $email,
                'password' => $password,
            ]);
            self::assertStringContainsString('Tenants', $opened->body);
            self::assertStringNotContainsString('Authenticator code', $opened->body);
            self::assertStringNotContainsString('Set up authenticator', $opened->body);

            $on = $passwordOnly->post(AdminDoor::path('/authenticator'), [
                'admin_mfa_enabled' => '1',
                'current' => $password,
            ]);
            self::assertStringContainsString('Authenticator is on. The next sign-in asks for a code.', $on->body);
            self::assertStringContainsString('name="admin_mfa_enabled" value="1" checked', $on->body);

            $again = self::client();
            $again->get(AdminDoor::path('/login'));
            $challenge = $again->post(AdminDoor::path('/login'), [
                'email' => $email,
                'password' => $password,
            ]);
            self::assertStringContainsString('Authenticator code', $challenge->body);
            $finished = $again->post(AdminDoor::path('/mfa'), [
                'code' => $this->freshCode($pdo, $email),
            ]);
            self::assertStringContainsString('Tenants', $finished->body);

            $disabled = $pdo->prepare(
                "SELECT count(*) FROM platform.admin_audit WHERE admin_email = ? AND action = 'admin.mfa_disabled'"
            );
            $disabled->execute([$email]);
            self::assertGreaterThanOrEqual(1, (int) $disabled->fetchColumn());
            $enabled = $pdo->prepare(
                "SELECT count(*) FROM platform.admin_audit WHERE admin_email = ? AND action = 'admin.mfa_enabled'"
            );
            $enabled->execute([$email]);
            self::assertGreaterThanOrEqual(1, (int) $enabled->fetchColumn());
        } finally {
            $clearSetting = $pdo->prepare("DELETE FROM platform.settings WHERE key = 'admin_mfa_enabled'");
            $clearSetting->execute();
            $clearFailures = $pdo->prepare('DELETE FROM platform.admin_mfa_failures WHERE email = ?');
            $clearFailures->execute([$email]);
            $clearSignInFailures = $pdo->prepare("DELETE FROM platform.sign_in_attempts WHERE door = 'admin' AND email_tried = ?");
            $clearSignInFailures->execute([strtolower($email)]);
            AdminDoor::clear($pdo, $email);
        }
    }

    private function freshCode(PDO $pdo, string $email): string
    {
        $used = $pdo->prepare('SELECT mfa_last_used_step FROM platform.admins WHERE lower(email) = lower(?)');
        $used->execute([$email]);
        $usedStep = $used->fetchColumn();
        self::assertTrue(is_string($usedStep) || is_int($usedStep));
        $step = max(Totp::stepForTime(time()), ((int) $usedStep) + 1);
        AdminDoor::waitUntilStep($step);

        return Totp::codeForStep(AdminDoor::SECRET, $step);
    }

    private function ensureAdmin(string $email, string $password): PDO
    {
        $pdo = LocalAdmin::open()->pdo();
        $statement = $pdo->prepare(
            'INSERT INTO platform.admins (id, email, password_hash)
             VALUES (uuidv7(), ?, ?)
             ON CONFLICT (email) DO UPDATE
                SET password_hash = EXCLUDED.password_hash'
        );
        $statement->execute([$email, (new PasswordChoice())->hash($password)]);

        return $pdo;
    }

    private function secret(string $html): string
    {
        self::assertSame(1, preg_match('/letter-spacing:0\.04em">([A-Z2-7 ]+)</', $html, $match));
        $raw = Base32::decode($match[1]);
        self::assertIsString($raw);

        return $raw;
    }
}
