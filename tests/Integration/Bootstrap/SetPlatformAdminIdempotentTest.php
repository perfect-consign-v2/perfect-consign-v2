<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Bootstrap;

use PDO;
use PHPUnit\Framework\TestCase;

final class SetPlatformAdminIdempotentTest extends TestCase
{
    private const EMAIL = 'platform-admin-idempotent@example.test';
    private const PASSWORD = 'PlatformAdminIdempotent1!';

    public function testAMatchingPasswordDoesNotBumpCredentialsVersion(): void
    {
        $root = dirname(__DIR__, 3);
        $pdo = $this->pdo();
        $this->ensureAdmin($pdo);
        $before = $this->credentialsVersion($pdo);
        $this->runSetPlatformAdmin($root);
        self::assertSame($before, $this->credentialsVersion($pdo));
        $this->runSetPlatformAdmin($root);
        self::assertSame($before, $this->credentialsVersion($pdo));
    }

    private function ensureAdmin(PDO $pdo): void
    {
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        self::assertNotFalse($hash);
        $statement = $pdo->prepare(
            'INSERT INTO platform.admins (id, email, password_hash)
             VALUES (uuidv7(), ?, ?)
             ON CONFLICT (email) DO UPDATE
                SET password_hash = EXCLUDED.password_hash'
        );
        $statement->execute([self::EMAIL, $hash]);
    }

    private function credentialsVersion(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT credentials_version
             FROM platform.admins
             WHERE lower(email) = lower(?)'
        );
        $statement->execute([self::EMAIL]);
        $version = $statement->fetchColumn();
        self::assertNotFalse($version);

        return (int) $version;
    }

    private function runSetPlatformAdmin(string $root): void
    {
        $previousEmail = getenv('STALL_PLATFORM_ADMIN_EMAIL');
        $previousPassword = getenv('STALL_PLATFORM_ADMIN_PASSWORD');
        $previousEmailEnv = $_ENV['STALL_PLATFORM_ADMIN_EMAIL'] ?? null;
        $previousPasswordEnv = $_ENV['STALL_PLATFORM_ADMIN_PASSWORD'] ?? null;
        putenv('STALL_PLATFORM_ADMIN_EMAIL=' . self::EMAIL);
        $_ENV['STALL_PLATFORM_ADMIN_EMAIL'] = self::EMAIL;
        putenv('STALL_PLATFORM_ADMIN_PASSWORD=' . self::PASSWORD);
        $_ENV['STALL_PLATFORM_ADMIN_PASSWORD'] = self::PASSWORD;
        try {
            $this->executeSetPlatformAdmin($root);
        } finally {
            $this->restoreEnv('STALL_PLATFORM_ADMIN_EMAIL', $previousEmail, $previousEmailEnv);
            $this->restoreEnv('STALL_PLATFORM_ADMIN_PASSWORD', $previousPassword, $previousPasswordEnv);
        }
    }

    private function executeSetPlatformAdmin(string $root): void
    {
        $php = PHP_BINARY;
        $script = $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'set-platform-admin.php';
        $command = [$php, $script];
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true]
        );
        self::assertIsResource($process);
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $stdout = isset($pipes[1]) && is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        $stderr = isset($pipes[2]) && is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }
        $code = proc_close($process);
        self::assertSame(0, $code, is_string($stderr) ? $stderr : '');
        self::assertIsString($stdout);
        self::assertTrue(
            str_contains($stdout, 'platform admin unchanged') || str_contains($stdout, 'platform admin saved'),
            $stdout
        );
    }

    private function restoreEnv(string $name, string|false $getenvValue, mixed $envValue): void
    {
        if ($getenvValue !== false) {
            putenv($name . '=' . $getenvValue);
        } else {
            putenv($name);
        }
        if (is_string($envValue)) {
            $_ENV[$name] = $envValue;
        } else {
            unset($_ENV[$name]);
        }
    }

    private function pdo(): PDO
    {
        $host = getenv('STALL_DB_HOST');
        $port = getenv('STALL_DB_PORT');
        $name = getenv('STALL_DB_NAME');
        $user = getenv('STALL_DB_USER');
        $password = getenv('STALL_DB_PASSWORD');
        $host = is_string($host) && $host !== '' ? $host : '127.0.0.1';
        $port = is_string($port) && $port !== '' ? $port : '5436';
        $name = is_string($name) && $name !== '' ? $name : 'consign';
        $user = is_string($user) && $user !== '' ? $user : 'stall';
        $password = is_string($password) && $password !== '' ? $password : 'stall';

        return new PDO(
            'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
}
