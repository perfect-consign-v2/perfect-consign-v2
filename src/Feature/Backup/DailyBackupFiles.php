<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

use DateTimeImmutable;
use DateTimeZone;

final class DailyBackupFiles
{
    private readonly string $root;

    private bool $refuseUnlink = false;

    public function failUnlink(): void
    {
        $this->refuseUnlink = true;
    }

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
        $this->refuseWebTree($real);
        $this->root = $real;
    }

    public function replace(string $schema, string $utcDate, string $sourceFile): void
    {
        $this->requireSchema($schema);
        $this->requireDate($utcDate);
        $this->requireSource($sourceFile);
        $directory = $this->tenantDirectory($schema);
        $this->store($sourceFile, $directory . DIRECTORY_SEPARATOR . $utcDate . '.zip');
    }

    public function removeOlderThan(DateTimeImmutable $today): void
    {
        $cutoff = $today->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0)->modify('-30 days')->format('Y-m-d');
        foreach ($this->names($this->root) as $name) {
            $this->pruneTenant($name, $cutoff);
        }
    }

    private function refuseWebTree(string $real): void
    {
        $repo = dirname(__DIR__, 3);
        $public = realpath($repo . DIRECTORY_SEPARATOR . 'public');
        if ($public !== false && ($real === $public || str_starts_with($real, $public . DIRECTORY_SEPARATOR))) {
            throw new BackupException('STALL_BACKUP_DIR must be outside the web tree');
        }
        if ($real === $repo || str_starts_with($real, $repo . DIRECTORY_SEPARATOR . 'public')) {
            throw new BackupException('STALL_BACKUP_DIR must be outside the web tree');
        }
    }

    private function requireSchema(string $schema): void
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new BackupException('schema name is not a tenant schema');
        }
    }

    private function requireDate(string $utcDate): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $utcDate) !== 1) {
            throw new BackupException('a backup date is required');
        }
    }

    private function requireSource(string $sourceFile): void
    {
        if (!is_file($sourceFile)) {
            throw new BackupException('The backup could not be stored.');
        }
    }

    private function tenantDirectory(string $schema): string
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . $schema;
        if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) {
            throw new BackupException('The backup could not be stored.');
        }

        return $directory;
    }

    private function store(string $sourceFile, string $target): void
    {
        $partial = $target . '.partial';
        $this->removeFile($partial);
        $this->copyFile($sourceFile, $partial);
        $this->removeFile($target);
        $this->moveFile($partial, $target);
    }

    private function removeFile(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $removed = $this->refuseUnlink ? false : unlink($path);
        $this->refuseUnlink = false;
        if ($removed !== true) {
            throw new BackupException('The backup could not be stored.');
        }
    }

    private function copyFile(string $source, string $target): void
    {
        if (!copy($source, $target)) {
            throw new BackupException('The backup could not be stored.');
        }
    }

    private function moveFile(string $source, string $target): void
    {
        if (!rename($source, $target)) {
            throw new BackupException('The backup could not be stored.');
        }
    }

    private function pruneTenant(string $name, string $cutoff): void
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $name) !== 1) {
            return;
        }
        $directory = $this->root . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        foreach ($this->names($directory) as $file) {
            $this->pruneFile($directory, $file, $cutoff);
        }
    }

    private function pruneFile(string $directory, string $file, string $cutoff): void
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\.zip$/', $file, $match) !== 1) {
            return;
        }
        if ($match[1] >= $cutoff) {
            return;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $file;
        if (!BackupPath::contains($this->root, $path)) {
            return;
        }
        $removed = $this->refuseUnlink ? false : unlink($path);
        $this->refuseUnlink = false;
        if ($removed !== true) {
            throw new BackupException('An old backup could not be removed.');
        }
    }

    /**
     * @return list<string>
     */
    private function names(string $directory): array
    {
        $names = scandir($directory);
        if ($names === false) {
            throw new BackupException('The backup directory could not be read.');
        }

        return $names;
    }
}
