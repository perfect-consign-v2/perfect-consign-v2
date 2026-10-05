<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

final class PostgresTools
{
    public function __construct(private readonly CommandProcess $process)
    {
    }

    /**
     * @param list<string> $arguments
     */
    public function pgDump(BackupConnection $connection, array $arguments, string $stdoutFile): void
    {
        $envFile = $this->dockerEnvFile($connection);
        try {
            $this->process->run(
                $this->command('pg_dump', $connection, $arguments, $envFile),
                $this->environment($connection),
                null,
                $stdoutFile,
                $connection->password
            );
        } finally {
            $this->removeEnvFile($envFile);
        }
    }

    public function psql(BackupConnection $connection, string $sqlFile): void
    {
        $envFile = $this->dockerEnvFile($connection);
        try {
            $this->process->run(
                $this->command('psql', $connection, ['--no-password', '--set=ON_ERROR_STOP=1'], $envFile),
                $this->environment($connection),
                $sqlFile,
                null,
                $connection->password
            );
        } finally {
            $this->removeEnvFile($envFile);
        }
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function command(string $tool, BackupConnection $connection, array $arguments, ?string $envFile): array
    {
        $configured = getenv('STALL_PG_DUMP');
        if (is_string($configured) && str_starts_with($configured, 'docker:')) {
            $container = substr($configured, strlen('docker:'));
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $container) !== 1) {
                throw new BackupException('STALL_PG_DUMP container name is invalid');
            }
            if ($envFile === null) {
                throw new BackupException('could not create the database password file');
            }

            return array_merge(
                ['docker', 'exec', '-i', '--env-file', $envFile, $container, $tool],
                $this->clientArguments($connection, false),
                $arguments
            );
        }

        $binary = $tool;
        if (is_string($configured) && $configured !== '') {
            $binary = $tool === 'pg_dump' ? $configured : $this->psqlBinary($configured);
        }

        return array_merge([$binary], $this->clientArguments($connection, true), $arguments);
    }

    /**
     * @return list<string>
     */
    private function clientArguments(BackupConnection $connection, bool $usePublishedPort): array
    {
        $port = $usePublishedPort ? $connection->port : '5432';
        $host = $usePublishedPort ? $connection->host : '127.0.0.1';

        return [
            '--no-password',
            '--host=' . $host,
            '--port=' . $port,
            '--username=' . $connection->user,
            '--dbname=' . $connection->database,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function environment(BackupConnection $connection): array
    {
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $env['PGPASSWORD'] = $connection->password;

        return $env;
    }

    private function psqlBinary(string $pgDump): string
    {
        $configured = getenv('STALL_PSQL');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        $sibling = dirname($pgDump) . DIRECTORY_SEPARATOR . (str_ends_with(strtolower($pgDump), '.exe') ? 'psql.exe' : 'psql');
        if (is_file($sibling)) {
            return $sibling;
        }

        return 'psql';
    }

    private function dockerEnvFile(BackupConnection $connection): ?string
    {
        $configured = getenv('STALL_PG_DUMP');
        if (!is_string($configured) || !str_starts_with($configured, 'docker:')) {
            return null;
        }
        $path = tempnam(sys_get_temp_dir(), 'stall-pg-');
        if ($path === false) {
            throw new BackupException('could not create the database password file');
        }
        if (!chmod($path, 0600)) {
            $this->removeEnvFile($path);
            throw new BackupException('could not protect the database password file');
        }
        $written = file_put_contents($path, 'PGPASSWORD=' . $connection->password . "\n");
        if ($written === false) {
            $this->removeEnvFile($path);
            throw new BackupException('could not write the database password file');
        }

        return $path;
    }

    private function removeEnvFile(?string $path): void
    {
        if ($path === null || !is_file($path)) {
            return;
        }
        if (!unlink($path) && is_file($path)) {
            throw new BackupException('could not remove the database password file');
        }
    }
}
