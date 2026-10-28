<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Bootstrap;

use PerfectConsign\Tests\Support\ProbeMall;
use PDO;
use PHPUnit\Framework\TestCase;

final class SetOwnerIdempotentTest extends TestCase
{
    public function testAMatchingPasswordDoesNotBumpCredentialsVersion(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->pdo();
        $before = $this->credentialsVersion($pdo);
        $this->runSetOwner($root);
        $afterSame = $this->credentialsVersion($pdo);
        self::assertSame($before, $afterSame);
        $this->runSetOwner($root);
        self::assertSame($before, $this->credentialsVersion($pdo));
    }

    private function credentialsVersion(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT o.credentials_version
             FROM platform.owners o
             INNER JOIN platform.tenants t ON t.id = o.tenant_id
             WHERE t.slug = ?'
        );
        $statement->execute([ProbeMall::SLUG]);
        $version = $statement->fetchColumn();
        self::assertNotFalse($version);

        return (int) $version;
    }

    private function runSetOwner(string $root): void
    {
        putenv('STALL_OWNER_PASSWORD=' . ProbeMall::PASSWORD);
        $_ENV['STALL_OWNER_PASSWORD'] = ProbeMall::PASSWORD;
        putenv('STALL_OWNER_EMAIL=' . ProbeMall::EMAIL);
        $_ENV['STALL_OWNER_EMAIL'] = ProbeMall::EMAIL;
        $php = PHP_BINARY;
        $script = $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'set-owner.php';
        $command = [
            $php,
            $script,
            ProbeMall::SLUG,
            ProbeMall::USERNAME,
        ];
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
            str_contains($stdout, 'owner unchanged') || str_contains($stdout, 'owner profile saved') || str_contains($stdout, 'owner saved'),
            $stdout
        );
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