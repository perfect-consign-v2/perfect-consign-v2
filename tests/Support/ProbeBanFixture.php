<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use PDO;
use RuntimeException;

/**
 * HTTP tests share 127.0.0.1. Two document 404s would ban that address for the rest of the suite.
 */
final class ProbeBanFixture
{
    public static function releaseLoopback(): void
    {
        self::release('127.0.0.1');
    }

    public static function connection(): PDO
    {
        return self::pdo();
    }

    public static function release(string $address): void
    {
        $pdo = self::pdo();
        $bans = $pdo->prepare('DELETE FROM platform.probe_bans WHERE client_address = ?');
        $bans->execute([$address]);
        $streaks = $pdo->prepare('DELETE FROM platform.probe_streaks WHERE client_address = ?');
        $streaks->execute([$address]);
    }

    private static function pdo(): PDO
    {
        $host = self::env('STALL_DB_HOST');
        $port = self::env('STALL_DB_PORT');
        $name = self::env('STALL_DB_NAME');
        $user = self::env('STALL_DB_USER');
        $password = self::env('STALL_DB_PASSWORD');

        return new PDO(
            'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            throw new RuntimeException($name . ' is required to release a probe ban');
        }

        return $value;
    }
}
