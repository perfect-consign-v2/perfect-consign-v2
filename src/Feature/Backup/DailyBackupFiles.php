<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

use DateTimeImmutable;
use DateTimeZone;

final class DailyBackupFiles
{
    private readonly string $root;

    public function __construct(string $root)
    {
        if ($root === '' || str_contains($root, 'perfect_consign_pg')) {
            throw new BackupException('STALL_BACKUP_DIR must be outside the database volume');
        }
        if (!is_dir($root)) {
            throw new BackupException('STALL_BACKUP_DIR is not a directory');
        }
        $real = realpath($root);
        if ($real === false || str_contains($real, 'perfect_consign_pg')) {
            throw new BackupException('STALL_BACKUP_DIR must be outside the database volume');
        }
        $this->root = $real;
    }

    public function replace(string $schema, string $utcDate, string $sourceFile): void
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new BackupException('schema name is not a tenant schema');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $utcDate) !== 1) {
            throw new BackupException('a backup date is required');
        }
        if (!is_file($sourceFile)) {
            throw new BackupException('The backup could not be stored.');
        }
        $directory = $this->root . DIRECTORY_SEPARATOR . $schema;
        if (!is_dir($directory) && !mkdir($directory, 0755) && !is_dir($directory)) {
            throw new BackupException('The backup could not be stored.');
        }
        $target = $directory . DIRECTORY_SEPARATOR . $utcDate . '.zip';
        $partial = $target . '.partial';
        if (is_file($partial) && !unlink($partial)) {
            throw new BackupException('The backup could not be stored.');
        }
        if (!copy($sourceFile, $partial)) {
            throw new BackupException('The backup could not be stored.');
        }
        if (is_file($target) && !unlink($target)) {
            throw new BackupException('The backup could not be stored.');
        }
        if (!rename($partial, $target)) {
            throw new BackupException('The backup could not be stored.');
        }
    }

    public function removeOlderThan(DateTimeImmutable $today): void
    {
        $cutoff = $today->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0)->modify('-30 days')->format('Y-m-d');
        $directories = scandir($this->root);
        if ($directories === false) {
            throw new BackupException('The backup directory could not be read.');
        }
        foreach ($directories as $name) {
            if (preg_match('/^tenant_[0-9a-f]{32}$/', $name) !== 1) {
                continue;
            }
            $directory = $this->root . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($directory) || is_link($directory)) {
                continue;
            }
            $files = scandir($directory);
            if ($files === false) {
                throw new BackupException('The backup directory could not be read.');
            }
            foreach ($files as $file) {
                if (preg_match('/^(\d{4}-\d{2}-\d{2})\.zip$/', $file, $match) !== 1) {
                    continue;
                }
                if ($match[1] >= $cutoff) {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $file;
                $real = realpath($path);
                $prefix = $this->root . DIRECTORY_SEPARATOR;
                if ($real === false || !str_starts_with($real, $prefix) || is_link($path)) {
                    continue;
                }
                if (!unlink($path)) {
                    throw new BackupException('An old backup could not be removed.');
                }
            }
        }
    }
}
