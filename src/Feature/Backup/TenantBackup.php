<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Log\LoggerInterface;

final class TenantBackup
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly PostgresTools $tools,
        private readonly BackupWorkspace $workspace,
        private readonly PlatformSnapshot $platform,
    ) {
    }

    public function create(BackupConnection $connection, string $slug): BackupFile
    {
        $this->requireSlug($slug);
        $takenAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $pdo = $connection->pdo();
        $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ');
        $directory = '';
        try {
            $file = $this->capture($connection, $pdo, $slug, $takenAt, $directory);
            $pdo->exec('COMMIT');

            return $file;
        } catch (BackupException $error) {
            $this->abort($pdo, $directory);
            throw $error;
        } catch (\Throwable $error) {
            $this->abort($pdo, $directory);
            $this->logger->error('backup failed', ['slug' => $slug, 'message' => $error->getMessage()]);
            throw new BackupException('The backup could not be taken.', 0, $error);
        }
    }

    public function send(BackupFile $file): bool
    {
        ignore_user_abort(true);
        $this->sendHeaders($file);
        $handle = fopen($file->path, 'rb');
        if ($handle === false) {
            throw new BackupException('The backup could not be opened.');
        }
        try {
            $this->writeBody($handle);
        } finally {
            fclose($handle);
        }

        return connection_aborted() !== 1;
    }

    public function delete(BackupFile $file): void
    {
        if (!$this->workspace->delete($file->directory)) {
            $this->logger->error('could not delete the backup temp directory', ['directory' => $file->directory]);
        }
    }

    private function requireSlug(string $slug): void
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $slug) !== 1) {
            throw new BackupException('a tenant slug must be a short lowercase name');
        }
    }

    private function capture(
        BackupConnection $connection,
        PDO $pdo,
        string $slug,
        DateTimeImmutable $takenAt,
        string &$directory,
    ): BackupFile {
        $snapshot = $this->snapshot($pdo);
        $tenant = $this->platform->tenant($pdo, $slug);
        $owner = $this->platform->owner($pdo, $tenant['id']);
        $backupId = $this->backupId($pdo);
        $directory = $this->workspace->make($backupId);
        $schemaHash = $this->dumpSchema($connection, $tenant['schema'], $snapshot, $directory);
        $this->writeNotes($pdo, $directory, $backupId, $takenAt, $tenant, $owner, $schemaHash);

        return $this->package($directory, $slug, $takenAt, $backupId);
    }

    private function abort(PDO $pdo, string $directory): void
    {
        $pdo->exec('ROLLBACK');
        if ($directory !== '') {
            $this->workspace->delete($directory);
        }
    }

    /**
     * @param array{id: string, slug: string, schema: string, display_name: string, created_at: string} $tenant
     * @param array{id: string, username: string, password_hash: string, created_at: string}|null $owner
     */
    private function writeNotes(
        PDO $pdo,
        string $directory,
        string $backupId,
        DateTimeImmutable $takenAt,
        array $tenant,
        ?array $owner,
        string $schemaHash,
    ): void {
        $platformPath = $directory . DIRECTORY_SEPARATOR . 'platform.sql';
        $this->workspace->write($platformPath, $this->platform->platformSql($pdo, $tenant, $owner));
        $manifestPath = $directory . DIRECTORY_SEPARATOR . 'manifest.txt';
        $this->workspace->write($manifestPath, $this->platform->manifest(
            $backupId,
            $takenAt->format('Y-m-d\TH:i:s\Z'),
            $tenant,
            $owner,
            $this->version($pdo),
            $schemaHash
        ));
        $this->workspace->zip(
            $directory . DIRECTORY_SEPARATOR . 'backup.zip',
            $directory . DIRECTORY_SEPARATOR . 'schema.sql',
            $platformPath,
            $manifestPath
        );
    }

    private function dumpSchema(BackupConnection $connection, string $schema, string $snapshot, string $directory): string
    {
        $schemaPath = $directory . DIRECTORY_SEPARATOR . 'schema.sql';
        $this->tools->pgDump($connection, [
            '--schema=' . $schema,
            '--no-owner',
            '--no-acl',
            '--format=p',
            '--snapshot=' . $snapshot,
        ], $schemaPath);

        return $this->schemaHash($schemaPath);
    }

    private function schemaHash(string $schemaPath): string
    {
        $schemaSize = filesize($schemaPath);
        $schemaHash = hash_file('sha256', $schemaPath);
        if (!is_int($schemaSize) || $schemaSize < 1 || !is_string($schemaHash) || preg_match('/^[0-9a-f]{64}$/', $schemaHash) !== 1) {
            throw new BackupException('The backup could not be taken.');
        }

        return $schemaHash;
    }

    private function package(string $directory, string $slug, DateTimeImmutable $takenAt, string $backupId): BackupFile
    {
        $zipPath = $directory . DIRECTORY_SEPARATOR . 'backup.zip';
        $byteSize = filesize($zipPath);
        $zipHash = hash_file('sha256', $zipPath);
        if (!is_int($byteSize) || !is_string($zipHash) || preg_match('/^[0-9a-f]{64}$/', $zipHash) !== 1) {
            throw new BackupException('The backup could not be taken.');
        }

        return new BackupFile(
            $backupId,
            $zipPath,
            $directory,
            $byteSize,
            $zipHash,
            $slug . '-backup-' . $takenAt->format('Ymd\THis\Z') . '.zip',
            $takenAt->format('Y-m-d\TH:i:s\Z')
        );
    }

    private function sendHeaders(BackupFile $file): void
    {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $file->downloadName . '"');
        header('Content-Length: ' . (string) $file->byteSize);
        header('Cache-Control: no-store');
    }

    /**
     * @param resource $handle
     */
    private function writeBody($handle): void
    {
        while (!feof($handle)) {
            if (connection_aborted() === 1) {
                return;
            }
            $chunk = fread($handle, 65536);
            if ($chunk === false) {
                throw new BackupException('The backup could not be read.');
            }
            if ($chunk === '') {
                return;
            }
            echo $chunk;
            flush();
        }
    }

    private function snapshot(PDO $pdo): string
    {
        $statement = $pdo->query('SELECT pg_export_snapshot()');
        $snapshot = $statement === false ? false : $statement->fetchColumn();
        if (!is_string($snapshot) || preg_match('/^[0-9A-Fa-f]+(?:-[0-9A-Fa-f]+)+$/', $snapshot) !== 1) {
            throw new BackupException('The backup could not be taken.');
        }

        return $snapshot;
    }

    private function backupId(PDO $pdo): string
    {
        $statement = $pdo->query('SELECT uuidv7()');
        $id = $statement === false ? false : $statement->fetchColumn();
        if (!is_string($id) || preg_match('/^[0-9a-f-]{36}$/', $id) !== 1) {
            throw new BackupException('The backup could not be taken.');
        }

        return $id;
    }

    private function version(PDO $pdo): string
    {
        $statement = $pdo->query("SELECT current_setting('server_version')");
        $version = $statement === false ? false : $statement->fetchColumn();
        if (!is_string($version) || $version === '' || str_contains($version, "\n")) {
            throw new BackupException('The backup could not be taken.');
        }

        return $version;
    }
}
