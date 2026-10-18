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

    public function testAPathThatIsNotADirectoryIsRefused(): void
    {
        $file = $this->root . DIRECTORY_SEPARATOR . 'not-dir';
        if (file_put_contents($file, 'x') === false) {
            self::fail('could not write a stand-in path');
        }
        $this->expectException(BackupException::class);
        new DailyBackupFiles($file);
    }

    public function testABadSchemaAndDateAndSourceAreRefused(): void
    {
        $files = new DailyBackupFiles($this->root);
        $source = $this->source('ok');
        try {
            $files->replace('public', '2026-09-26', $source);
            self::fail('a public schema was stored');
        } catch (BackupException $error) {
            self::assertSame('schema name is not a tenant schema', $error->getMessage());
        }
        try {
            $files->replace('tenant_' . str_repeat('e', 32), '26-09-2026', $source);
            self::fail('a bad date was stored');
        } catch (BackupException $error) {
            self::assertSame('a backup date is required', $error->getMessage());
        }
        try {
            $files->replace('tenant_' . str_repeat('e', 32), '2026-09-26', $this->root . DIRECTORY_SEPARATOR . 'missing.zip');
            self::fail('a missing source was stored');
        } catch (BackupException $error) {
            self::assertSame('The backup could not be stored.', $error->getMessage());
        }
        unlink($source);
    }

    public function testAnEmptyDirectoryIsRefused(): void
    {
        try {
            new DailyBackupFiles('');
            self::fail('an empty backup directory was accepted');
        } catch (BackupException $exception) {
            self::assertSame('STALL_BACKUP_DIR must be outside the database volume', $exception->getMessage());
        }
    }

    public function testAFileBlockingTheTenantFolderIsRefused(): void
    {
        $schema = 'tenant_' . str_repeat('c', 32);
        $blocked = $this->root . DIRECTORY_SEPARATOR . $schema;
        $written = file_put_contents($blocked, 'not a directory');
        if ($written === false) {
            self::fail('could not block the tenant folder');
        }
        $source = $this->source('blocked');
        set_error_handler(static fn (int $severity, string $message): bool => true);
        try {
            (new DailyBackupFiles($this->root))->replace($schema, '2026-09-26', $source);
            self::fail('a file in place of the tenant folder was accepted');
        } catch (BackupException) {
            self::assertTrue(true);
        } finally {
            restore_error_handler();
        }
    }

    public function testAFileNamedLikeATenantIsLeftAlone(): void
    {
        $schema = 'tenant_' . str_repeat('d', 32);
        $blocked = $this->root . DIRECTORY_SEPARATOR . $schema;
        $written = file_put_contents($blocked, 'not a directory');
        if ($written === false) {
            self::fail('could not write a tenant-named file');
        }
        $files = new DailyBackupFiles($this->root);
        $files->removeOlderThan(new DateTimeImmutable('2026-09-26 12:00:00+00'));
        self::assertFileExists($blocked);
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
