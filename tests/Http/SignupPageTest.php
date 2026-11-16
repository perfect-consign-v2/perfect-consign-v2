<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Feature\Signup\PlatformSettings;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeServer;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class SignupPageTest extends TestCase
{
    public function testTheLiveSignupPageFollowsThePlatformSwitch(): void
    {
        $pdo = $this->database();
        $previous = $this->setting($pdo, 'store_signup_enabled');
        $verification = $this->setting($pdo, 'store_signup_require_email_verification');
        $settings = new PlatformSettings($pdo, $this->createStub(LoggerInterface::class));
        $server = null;
        try {
            $settings->setStoreSignupEnabled(false);
            $server = ProbeServer::startOn(dirname(__DIR__, 2), 8099);
            $client = new FormClient($server->baseUrl());
            $off = $client->get('/signup');
            self::assertSame(200, $off->status);
            self::assertStringContainsString('Self-serve signup is turned off', $off->body);

            $settings->setStoreSignupEnabled(true);
            $settings->setRequireEmailVerification(true);
            $on = $client->get('/signup');
            self::assertSame(200, $on->status);
            self::assertStringContainsString('name="store_name"', $on->body);
            self::assertStringNotContainsString('name="password"', $on->body);
            self::assertStringNotContainsString('Self-serve signup is turned off', $on->body);

            $settings->setRequireEmailVerification(false);
            $open = $client->get('/signup');
            self::assertStringContainsString('name="password"', $open->body);
            $settings->setRequireEmailVerification(true);

            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $verify = $client->get('/signup/verify/' . $token);
            self::assertStringContainsString('That confirmation link is invalid or expired.', $verify->body);
            $posted = $client->post('/signup/verify/' . $token, [
                'password' => 'password1',
                'password_confirm' => 'password1',
            ]);
            self::assertStringContainsString('That confirmation link is invalid or expired.', $posted->body);
        } finally {
            if ($server !== null) {
                $server->stop();
            }
            $this->restore($pdo, 'store_signup_enabled', $previous);
            $this->restore($pdo, 'store_signup_require_email_verification', $verification);
        }
    }

    public function testALiveSignupChoosesThePasswordOnConfirm(): void
    {
        $pdo = $this->database();
        $previous = $this->setting($pdo, 'store_signup_enabled');
        $verification = $this->setting($pdo, 'store_signup_require_email_verification');
        $settings = new PlatformSettings($pdo, $this->createStub(LoggerInterface::class));
        $slug = 'sig' . bin2hex(random_bytes(3));
        $email = $slug . '@example.test';
        $server = null;
        $created = false;
        try {
            $settings->setStoreSignupEnabled(true);
            $settings->setRequireEmailVerification(true);
            $cleared = $pdo->prepare('DELETE FROM platform.signup_attempts WHERE client_address = ?');
            $cleared->execute(['127.0.0.1']);
            $written = file_put_contents(ProbeServer::mailFile(), '');
            if ($written === false) {
                self::fail('the mail file could not be cleared');
            }
            $server = ProbeServer::startOn(dirname(__DIR__, 2), 8101);
            $client = new FormClient($server->baseUrl());
            $client->get('/signup');
            $sent = $client->post('/signup', [
                'store_name' => $slug,
                'email' => $email,
                'password' => 'ignored-password',
                'password_confirm' => 'ignored-password',
            ]);
            self::assertStringContainsString('Check your email', $sent->body);
            $created = true;
            $mail = file_get_contents(ProbeServer::mailFile());
            self::assertIsString($mail);
            self::assertSame(1, preg_match('#/signup/verify/([A-Za-z0-9_-]{43})#', $mail, $tokens));
            $page = $client->get('/signup/verify/' . $tokens[1]);
            self::assertStringContainsString('Choose your password', $page->body);
            self::assertStringNotContainsString('Email confirmed', $page->body);
            $short = $client->post('/signup/verify/' . $tokens[1], [
                'password' => 'short',
                'password_confirm' => 'short',
            ]);
            self::assertStringContainsString('Password must be at least 8 characters.', $short->body);
            $saved = $client->post('/signup/verify/' . $tokens[1], [
                'password' => 'chosen-password',
                'password_confirm' => 'chosen-password',
            ]);
            self::assertStringContainsString('Email confirmed. Sign in to open your store.', $saved->body);
            $home = $client->signInByEmail($email, 'chosen-password');
            self::assertStringNotContainsString('That email or password is wrong.', $home->body);
            self::assertStringContainsString($slug, $home->body);
        } finally {
            if ($server !== null) {
                $server->stop();
            }
            $this->restore($pdo, 'store_signup_enabled', $previous);
            $this->restore($pdo, 'store_signup_require_email_verification', $verification);
            if ($created) {
                require_once dirname(__DIR__, 2) . '/bin/wipe-tenant.php';
                wipeTenant($pdo, $slug, $slug);
            }
        }
    }

    private function database(): PDO
    {
        $host = getenv('STALL_DB_HOST');
        $port = getenv('STALL_DB_PORT');
        $name = getenv('STALL_DB_NAME');
        $user = getenv('STALL_DB_USER');
        $password = getenv('STALL_DB_PASSWORD');
        if (
            !is_string($host) || $host === ''
            || !is_string($port) || $port === ''
            || !is_string($name) || $name === ''
            || !is_string($user) || $user === ''
            || !is_string($password) || $password === ''
        ) {
            throw new RuntimeException('STALL_DB_HOST, STALL_DB_PORT, STALL_DB_NAME, STALL_DB_USER, and STALL_DB_PASSWORD are required');
        }

        return new PDO(
            'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function setting(PDO $pdo, string $key): mixed
    {
        $statement = $pdo->prepare('SELECT value FROM platform.settings WHERE key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    private function restore(PDO $pdo, string $key, mixed $previous): void
    {
        if ($previous === null) {
            $delete = $pdo->prepare('DELETE FROM platform.settings WHERE key = ?');
            $delete->execute([$key]);

            return;
        }
        if (is_bool($previous)) {
            $previous = $previous ? 'true' : 'false';
        }
        if (!is_string($previous)) {
            throw new RuntimeException('the previous signup setting could not be restored');
        }
        $update = $pdo->prepare(
            'INSERT INTO platform.settings (key, value) VALUES (?, ?::jsonb)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value'
        );
        $update->execute([$key, $previous]);
    }
}
