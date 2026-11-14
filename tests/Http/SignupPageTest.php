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
            $on = $client->get('/signup');
            self::assertSame(200, $on->status);
            self::assertStringContainsString('name="store_name"', $on->body);
            self::assertStringNotContainsString('Self-serve signup is turned off', $on->body);

            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $verify = $client->get('/signup/verify/' . $token);
            self::assertStringContainsString('That confirmation link is invalid or expired.', $verify->body);
        } finally {
            if ($server !== null) {
                $server->stop();
            }
            $this->restore($pdo, 'store_signup_enabled', $previous);
        }
    }

    private function database(): PDO
    {
        $host = getenv('STALL_DB_HOST');
        $port = getenv('STALL_DB_PORT');
        $name = getenv('STALL_DB_NAME');
        $user = getenv('STALL_DB_USER');
        $password = getenv('STALL_DB_PASSWORD');
        if (!is_string($host) || $host === ''
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
