<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

use RuntimeException;

final class DatabaseConfig
{
    /**
     * @return array{host: string, port: int, dbname: string, username: string, password: string}
     */
    public static function fromEnv(): array
    {
        $host = self::text('STALL_DB_HOST', '127.0.0.1');
        $name = self::text('STALL_DB_NAME', 'consign');
        $password = getenv('STALL_APP_PASSWORD');
        if (!is_string($password) || $password === '') {
            throw new RuntimeException('STALL_APP_PASSWORD is required');
        }
        if (str_contains($host, ';') || str_contains($name, ';')) {
            throw new RuntimeException('STALL_DB_HOST or STALL_DB_NAME is invalid');
        }

        return [
            'host' => $host,
            'port' => self::port(),
            'dbname' => $name,
            'username' => 'stall_app',
            'password' => $password,
        ];
    }

    private static function text(string $key, string $default): string
    {
        $value = getenv($key);
        if (!is_string($value) || $value === '') {
            return $default;
        }

        return $value;
    }

    private static function port(): int
    {
        $port = self::text('STALL_DB_PORT', '5436');
        if (!ctype_digit($port)) {
            throw new RuntimeException('STALL_DB_PORT is invalid');
        }
        $portNumber = (int) $port;
        if ($portNumber < 1 || $portNumber > 65535) {
            throw new RuntimeException('STALL_DB_PORT is invalid');
        }

        return $portNumber;
    }
}
