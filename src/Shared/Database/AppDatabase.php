<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Database;

use PDO;
use PerfectApp\Database\PostgresConnection;

final class AppDatabase
{
    /**
     * @param array<string, mixed> $config
     */
    public function connect(array $config): PDO
    {
        $port = $config['port'] ?? null;
        if (is_string($port) && ctype_digit($port)) {
            $config['port'] = (int) $port;
        }
        $config['charset'] = 'utf8';
        $config['options'] = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        return (new PostgresConnection())->connect($config);
    }
}
