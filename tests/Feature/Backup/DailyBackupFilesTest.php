<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Backup;

use DateTimeImmutable;
use PerfectConsign\Feature\Backup\BackupException;
use PerfectConsign\Feature\Backup\DailyBackupFiles;
use PHPUnit\Framework\TestCase;

final class DailyBackupFilesTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'perfect-consign-backup-test-' . bin2hex(random_bytes(4));
        if (!mkdir($this->root, 0700) && !is_dir($this->root)) {
            self::fail('could not create a temp directory');
        }
    }

    protected function tearDown(): void
    {
        if ($this->root === '' || !is_dir($this->root)) {
            return;
        }
        $directories = scandir($this->root);
        if ($directories === false) {
            return;
        }
        foreach ($directories as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $this->root . DIRECTORY_SEPARATOR . $name;
            if (is_dir($path)) {
                $files = scandir($path);
                if (is_array($files)) {
                    foreach ($files as $file) {
                        if ($file === '.' || $file === '..') {
                            continue;
                        }
                        unlink($path . DIRECTORY_SEPARATOR . $file);
                    }
                }
                rmdir($path);
                continue;
            }
            unlink($path);
        }
        rmdir($this->root);
    }

    public function testASecondCopyOnTheSameDayReplacesTheFile(): void
    {
        $schema = 'tenant_' . str_repeat('a', 32);
        $first = $this->source('one');
        $second = $this->source('two');
        $files = new DailyBackupFiles($this->root);
        $files->replace($schema, '2026-09-26', $first);
        $files->replace($schema, '2026-09-26', $second);

        $stored = file_get_contents($this->root . DIRECTORY_SEPARATOR . $schema . DIRECTORY_SEPARATOR . '2026-09-26.zip');
        self::assertSame('two', $stored);
        unlink($first);
        unlink($second);
    }

    public function testFilesOlderThanThirtyDaysAreRemoved(): void
    {
        $schema = 'tenant_' . str_repeat('b', 32);
        $old = $this->source('old');
        $kept = $this->source('kept');
        $files = new DailyBackupFiles($this->root);
        $files->replace($schema, '2026-08-26', $old);
        $files->replace($schema, '2026-08-27', $kept);
        $files->removeOlderThan(new DateTimeImmutable('2026-09-26 12:00:00+00'));

        $directory = $this->root . DIRECTORY_SEPARATOR . $schema;
        self::assertFileDoesNotExist($directory . DIRECTORY_SEPARATOR . '2026-08-26.zip');
        self::assertFileExists($directory . DIRECTORY_SEPARATOR . '2026-08-27.zip');
        unlink($old);
        unlink($kept);
    }

    public function testTheDatabaseVolumeIsRefused(): void
    {
        $this->expectException(BackupException::class);
        new DailyBackupFiles('C:\\data\\perfect_consign_pg\\backups');
    }

    private function source(string $contents): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . $contents . '.src';
        $written = file_put_contents($path, $contents);
        if ($written === false) {
            self::fail('could not write a source file');
        }

        return $path;
    }
}
