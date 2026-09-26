<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

use Psr\Log\LoggerInterface;
use ZipArchive;

final class BackupWorkspace
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly DirectoryTree $tree,
    ) {
    }

    public function make(string $backupId): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'perfect-consign-backup-' . $backupId;
        if (!mkdir($directory, 0700) && !is_dir($directory)) {
            $this->logger->error('could not create a backup directory', ['directory' => $directory]);
            throw new BackupException('The backup could not be taken.');
        }

        return $directory;
    }

    public function write(string $path, string $contents): void
    {
        $written = file_put_contents($path, $contents);
        if ($written === false) {
            $this->logger->error('could not write a backup file', ['path' => $path, 'error' => error_get_last()]);
            throw new BackupException('The backup could not be taken.');
        }
    }

    public function zip(string $zipPath, string $schemaPath, string $platformPath, string $manifestPath): void
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

    public function delete(string $directory): bool
    {
        return $this->tree->remove($directory);
    }
}
