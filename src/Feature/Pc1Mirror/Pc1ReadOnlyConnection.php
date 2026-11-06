<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PDO;

final class Pc1ReadOnlyConnection
{
    public function __construct(
        public readonly string $host,
        public readonly string $port,
        public readonly string $database,
        public readonly string $user,
        public readonly string $password,
    ) {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $host) !== 1) {
            throw new MirrorException('PC1 database host is invalid');
        }
        if (preg_match('/^[0-9]+$/', $port) !== 1) {
            throw new MirrorException('PC1 database port is invalid');
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $database) !== 1) {
            throw new MirrorException('PC1 database name is invalid');
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $user) !== 1) {
            throw new MirrorException('PC1 database user is invalid');
        }
        if ($password === '' || preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
            throw new MirrorException('PC1 database password is required');
        }
        if (str_contains(strtolower($host), 'v2.perfectconsign.com')) {
            throw new MirrorException('PC1 extract must not use the v2 demo host');
        }
    }

    public static function fromEnv(): self
    {
        $password = self::envFirst(['PROD_DB_PASS', 'PROD_DB_PASSWORD', 'PC_DB_PASSWORD', 'PC_DB_PASS']);
        if ($password === null) {
            throw new MirrorException('PROD_DB_PASS (or PC_DB_PASSWORD) is required for extract');
        }

        return new self(
            self::envFirst(['PROD_DB_HOST', 'PC_DB_HOST']) ?? MirrorConstants::PROD_HOST,
            self::envFirst(['PROD_DB_PORT', 'PC_DB_PORT']) ?? '5432',
            self::envFirst(['PROD_DB_NAME', 'PC_DB_NAME']) ?? 'perfectconsign-api',
            self::envFirst(['PROD_DB_USER', 'PC_DB_USER']) ?? 'api-perfectconsign',
            $password,
        );
    }

    public function pdo(): PDO
    {
        $pdo = new PDO(
            'pgsql:host=' . $this->host . ';port=' . $this->port . ';dbname=' . $this->database,
            $this->user,
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec('SET default_transaction_read_only = on');
        $flag = $pdo->query('SHOW default_transaction_read_only');
        if ($flag === false || strtolower((string) $flag->fetchColumn()) !== 'on') {
            throw new MirrorException('PC1 session is not read-only');
        }

        return $pdo;
    }

    public function label(): string
    {
        return $this->host . ':' . $this->port . '/' . $this->database;
    }

    /**
     * @param list<string> $keys
     */
    private static function envFirst(array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
