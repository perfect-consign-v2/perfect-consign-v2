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
