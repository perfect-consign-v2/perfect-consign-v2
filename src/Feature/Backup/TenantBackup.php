<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Log\LoggerInterface;
use ZipArchive;

final class TenantBackup
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly PostgresTools $tools,
    ) {
    }

    public function create(BackupConnection $connection, string $slug): BackupFile
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $slug) !== 1) {
            throw new BackupException('a tenant slug must be a short lowercase name');
        }
        $takenAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $pdo = $connection->pdo();
        $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ');
        $open = true;
        $directory = null;
        try {
            $snapshot = $this->snapshot($pdo);
            $tenant = $this->tenant($pdo, $slug);
            $owner = $this->owner($pdo, $tenant['id']);
            $backupId = $this->backupId($pdo);
            $directory = $this->directory($backupId);
            $schemaPath = $directory . DIRECTORY_SEPARATOR . 'schema.sql';
            $this->tools->pgDump($connection, [
                '--schema=' . $tenant['schema'],
                '--no-owner',
                '--no-acl',
                '--format=p',
                '--snapshot=' . $snapshot,
            ], $schemaPath);
            $schemaSize = filesize($schemaPath);
            if (!is_int($schemaSize) || $schemaSize < 1) {
                throw new BackupException('The backup could not be taken.');
            }
            $schemaHash = hash_file('sha256', $schemaPath);
            if (!is_string($schemaHash) || preg_match('/^[0-9a-f]{64}$/', $schemaHash) !== 1) {
                throw new BackupException('The backup could not be taken.');
            }
            $platformPath = $directory . DIRECTORY_SEPARATOR . 'platform.sql';
            $this->write($platformPath, $this->platformSql($pdo, $tenant, $owner));
            $manifestPath = $directory . DIRECTORY_SEPARATOR . 'manifest.txt';
            $this->write($manifestPath, $this->manifest(
                $backupId,
                $takenAt,
                $tenant,
                $owner,
                $this->version($pdo),
                $schemaHash
            ));
            $zipPath = $directory . DIRECTORY_SEPARATOR . 'backup.zip';
            $this->zip($zipPath, $schemaPath, $platformPath, $manifestPath);
            $byteSize = filesize($zipPath);
            $zipHash = hash_file('sha256', $zipPath);
            if (!is_int($byteSize) || $byteSize < 0 || !is_string($zipHash) || preg_match('/^[0-9a-f]{64}$/', $zipHash) !== 1) {
                throw new BackupException('The backup could not be taken.');
            }
            $pdo->exec('COMMIT');
            $open = false;

            return new BackupFile(
                $backupId,
                $zipPath,
                $directory,
                $byteSize,
                $zipHash,
                $slug . '-backup-' . $takenAt->format('Ymd\THis\Z') . '.zip',
                $takenAt->format('Y-m-d\TH:i:s\Z')
            );
        } catch (BackupException $error) {
            if ($open) {
                $pdo->exec('ROLLBACK');
            }
            if ($directory !== null) {
                $this->deleteDirectory($directory);
            }
            throw $error;
        } catch (\Throwable $error) {
            if ($open) {
                $pdo->exec('ROLLBACK');
            }
            if ($directory !== null) {
                $this->deleteDirectory($directory);
            }
            $this->logger->error('backup failed', ['slug' => $slug, 'message' => $error->getMessage()]);
            throw new BackupException('The backup could not be taken.', 0, $error);
        }
    }

    public function send(BackupFile $file): bool
    {
        ignore_user_abort(true);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $file->downloadName . '"');
        header('Content-Length: ' . (string) $file->byteSize);
        header('Cache-Control: no-store');
        $handle = fopen($file->path, 'rb');
        if ($handle === false) {
            throw new BackupException('The backup could not be opened.');
        }
        try {
            while (!feof($handle)) {
                if (connection_aborted() === 1) {
                    return false;
                }
                $chunk = fread($handle, 65536);
                if ($chunk === false) {
                    throw new BackupException('The backup could not be read.');
                }
                if ($chunk === '') {
                    break;
                }
                echo $chunk;
                flush();
            }
        } finally {
            fclose($handle);
        }

        return connection_aborted() !== 1;
    }

    public function delete(BackupFile $file): void
    {
        if (!$this->deleteDirectory($file->directory)) {
            $this->logger->error('could not delete the backup temp directory', ['directory' => $file->directory]);
        }
    }

    /**
     * @return array{id: string, slug: string, schema: string, display_name: string, created_at: string}
     */
    private function tenant(PDO $pdo, string $slug): array
    {
        $statement = $pdo->prepare(
            'SELECT id, slug, schema_name, display_name, created_at FROM platform.tenants WHERE slug = ?'
        );
        $statement->execute([$slug]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || !is_string($row['id'] ?? null)
            || !is_string($row['schema_name'] ?? null)
            || !is_string($row['display_name'] ?? null)
            || !is_string($row['created_at'] ?? null)
            || preg_match('/^tenant_[0-9a-f]{32}$/', $row['schema_name']) !== 1
        ) {
            throw new BackupException('That mall has no backup.');
        }

        return [
            'id' => $row['id'],
            'slug' => $slug,
            'schema' => $row['schema_name'],
            'display_name' => $row['display_name'],
            'created_at' => $row['created_at'],
        ];
    }

    /**
     * @return array{id: string, username: string, password_hash: string, created_at: string}|null
     */
    private function owner(PDO $pdo, string $tenantId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id, username, password_hash, created_at FROM platform.owners WHERE tenant_id = ?::uuid'
        );
        $statement->execute([$tenantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!is_array($row)
            || !is_string($row['id'] ?? null)
            || !is_string($row['username'] ?? null)
            || !is_string($row['password_hash'] ?? null)
            || !is_string($row['created_at'] ?? null)
        ) {
            throw new BackupException('The backup could not be taken.');
        }
        if (str_contains($row['password_hash'], "\n") || str_contains($row['username'], "\n")) {
            throw new BackupException('The backup could not be taken.');
        }

        return [
            'id' => $row['id'],
            'username' => $row['username'],
            'password_hash' => $row['password_hash'],
            'created_at' => $row['created_at'],
        ];
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

    /**
     * @param array{id: string, slug: string, schema: string, display_name: string, created_at: string} $tenant
     * @param array{id: string, username: string, password_hash: string, created_at: string}|null $owner
     */
    private function platformSql(PDO $pdo, array $tenant, ?array $owner): string
    {
        $sql = 'INSERT INTO platform.tenants (id, slug, schema_name, display_name, created_at) VALUES ('
            . $this->quote($pdo, $tenant['id']) . ', '
            . $this->quote($pdo, $tenant['slug']) . ', '
            . $this->quote($pdo, $tenant['schema']) . ', '
            . $this->quote($pdo, $tenant['display_name']) . ', '
            . $this->quote($pdo, $tenant['created_at'])
            . ");\n";
        if ($owner === null) {
            return $sql;
        }

        return $sql . 'INSERT INTO platform.owners (id, tenant_id, username, password_hash, created_at) VALUES ('
            . $this->quote($pdo, $owner['id']) . ', '
            . $this->quote($pdo, $tenant['id']) . ', '
            . $this->quote($pdo, $owner['username']) . ', '
            . $this->quote($pdo, $owner['password_hash']) . ', '
            . $this->quote($pdo, $owner['created_at'])
            . ");\n";
    }

    /**
     * @param array{id: string, slug: string, schema: string, display_name: string, created_at: string} $tenant
     * @param array{id: string, username: string, password_hash: string, created_at: string}|null $owner
     */
    private function manifest(
        string $backupId,
        DateTimeImmutable $takenAt,
        array $tenant,
        ?array $owner,
        string $version,
        string $schemaHash,
    ): string {
        $text = 'backup_id=' . $backupId . "\n"
            . 'taken_at=' . $takenAt->format('Y-m-d\TH:i:s\Z') . "\n"
            . 'tenant_id=' . $tenant['id'] . "\n"
            . 'slug=' . $tenant['slug'] . "\n"
            . 'schema=' . $tenant['schema'] . "\n"
            . 'postgres=' . $version . "\n"
            . 'schema_sha256=' . $schemaHash . "\n";
        if ($owner === null) {
            return $text;
        }

        return $text
            . 'owner_id=' . $owner['id'] . "\n"
            . 'owner_username=' . $owner['username'] . "\n"
            . 'owner_password_hash=' . $owner['password_hash'] . "\n";
    }

    private function directory(string $backupId): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'perfect-consign-backup-' . $backupId;
        if (!mkdir($directory, 0700) && !is_dir($directory)) {
            $this->logger->error('could not create a backup directory', ['directory' => $directory]);
            throw new BackupException('The backup could not be taken.');
        }

        return $directory;
    }

    private function write(string $path, string $contents): void
    {
        $written = file_put_contents($path, $contents);
        if ($written === false) {
            $this->logger->error('could not write a backup file', ['path' => $path, 'error' => error_get_last()]);
            throw new BackupException('The backup could not be taken.');
        }
    }

    private function zip(string $zipPath, string $schemaPath, string $platformPath, string $manifestPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BackupException('The backup could not be taken.');
        }
        $added = $zip->addFile($schemaPath, 'schema.sql')
            && $zip->addFile($platformPath, 'platform.sql')
            && $zip->addFile($manifestPath, 'manifest.txt')
            && $zip->close();
        if ($added !== true) {
            throw new BackupException('The backup could not be taken.');
        }
    }

    private function quote(PDO $pdo, string $value): string
    {
        $quoted = $pdo->quote($value);
        if ($quoted === false) {
            throw new BackupException('The backup could not be taken.');
        }

        return $quoted;
    }

    private function deleteDirectory(string $directory): bool
    {
        if (!is_dir($directory)) {
            return true;
        }
        $items = scandir($directory);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_link($path)) {
                if (!unlink($path)) {
                    return false;
                }
                continue;
            }
            if (is_dir($path)) {
                if (!$this->deleteDirectory($path)) {
                    return false;
                }
                continue;
            }
            if (!unlink($path)) {
                return false;
            }
        }

        return rmdir($directory);
    }
}
