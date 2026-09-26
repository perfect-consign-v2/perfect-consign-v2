<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

use PDO;

final class BackupConnection
{
    public function __construct(
        public readonly string $host,
        public readonly string $port,
        public readonly string $database,
        public readonly string $user,
        public readonly string $password,
    ) {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $host) !== 1) {
            throw new BackupException('database host is invalid');
        }
        if (preg_match('/^[0-9]+$/', $port) !== 1) {
            throw new BackupException('database port is invalid');
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $database) !== 1) {
            throw new BackupException('database name is invalid');
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $user) !== 1) {
            throw new BackupException('database user is invalid');
        }
        if ($password === '' || preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
            throw new BackupException('database password is required');
        }
    }

    public static function stallBackup(): self
    {
        return self::fromEnv(
            'STALL_DB_HOST',
            'STALL_DB_PORT',
            'STALL_DB_NAME',
            'stall_backup',
            'STALL_BACKUP_PASSWORD'
        );
    }

    public static function sourceBackup(): self
    {
        return self::fromEnv(
            'STALL_SOURCE_DB_HOST',
            'STALL_SOURCE_DB_PORT',
            'STALL_SOURCE_DB_NAME',
            'stall_backup',
            'STALL_SOURCE_DB_PASSWORD'
        );
    }

    public static function admin(): self
    {
        $user = getenv('STALL_DB_USER');
        if (!is_string($user) || $user === '') {
            throw new BackupException('STALL_DB_USER is required');
        }

        return self::fromEnv('STALL_DB_HOST', 'STALL_DB_PORT', 'STALL_DB_NAME', $user, 'STALL_DB_PASSWORD');
    }

    public function pdo(): PDO
    {
        return new PDO(
            'pgsql:host=' . $this->host . ';port=' . $this->port . ';dbname=' . $this->database,
            $this->user,
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    public function isLocalRestoreTarget(): bool
    {
        $host = strtolower($this->host);

        return ($host === '127.0.0.1' || $host === 'localhost')
            && $this->port === '5436'
            && $this->database === 'consign';
    }

    private static function fromEnv(
        string $hostKey,
        string $portKey,
        string $databaseKey,
        string $user,
        string $passwordKey,
    ): self {
        $host = getenv($hostKey);
        $port = getenv($portKey);
        $database = getenv($databaseKey);
        $password = getenv($passwordKey);
        if (!is_string($host) || $host === ''
            || !is_string($port) || $port === ''
            || !is_string($database) || $database === ''
            || !is_string($password) || $password === ''
        ) {
            throw new BackupException($hostKey . ', ' . $portKey . ', ' . $databaseKey . ', and ' . $passwordKey . ' are required');
        }

        return new self($host, $port, $database, $user, $password);
    }
}
