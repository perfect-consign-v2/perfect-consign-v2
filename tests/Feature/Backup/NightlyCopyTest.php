<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Backup;

use PerfectConsign\Feature\Backup\DirectoryTree;
use PerfectConsign\Feature\Backup\NightlyCopy;
use PHPUnit\Framework\TestCase;

final class NightlyCopyTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        $previous = getenv('STALL_BACKUP_DIR');
        if (is_string($previous) && $previous !== '') {
            putenv('STALL_BACKUP_DIR=' . $previous);
        } else {
            putenv('STALL_BACKUP_DIR');
        }
        if ($this->root !== '' && is_dir($this->root)) {
            (new DirectoryTree())->remove($this->root);
        }
    }

    public function testAMissingDirectoryExplainsThereIsNoCopy(): void
    {
        putenv('STALL_BACKUP_DIR');
        $copy = new NightlyCopy();

        self::assertStringContainsString('No nightly copy is on this server yet', $copy->headline('tenant_' . str_repeat('a', 32)));
        self::assertStringContainsString('No nightly copy', $copy->headline('not-a-schema'));
    }

    public function testTheNewestZipDateIsTheHeadline(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-nightly-' . bin2hex(random_bytes(4));
        if (!mkdir($this->root, 0700) && !is_dir($this->root)) {
            self::fail('could not create a backup directory');
        }
        $schema = 'tenant_' . str_repeat('b', 32);
        $dir = $this->root . DIRECTORY_SEPARATOR . $schema;
        if (!mkdir($dir, 0700) && !is_dir($dir)) {
            self::fail('could not create a tenant backup directory');
        }
        if (file_put_contents($dir . DIRECTORY_SEPARATOR . 'readme.txt', 'x') === false) {
            self::fail('could not write a skip file');
        }
        if (file_put_contents($dir . DIRECTORY_SEPARATOR . '2026-08-01.zip', 'old') === false) {
            self::fail('could not write an old zip');
        }
        if (file_put_contents($dir . DIRECTORY_SEPARATOR . '2026-09-15.zip', 'new') === false) {
            self::fail('could not write a new zip');
        }
        putenv('STALL_BACKUP_DIR=' . $this->root);

        self::assertStringContainsString('September 15, 2026', (new NightlyCopy())->headline($schema));
    }

    public function testABadSchemaDoesNotReadADirectoryOfThatName(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-nightly-' . bin2hex(random_bytes(4));
        $schema = 'not-a-schema';
        $dir = $this->root . DIRECTORY_SEPARATOR . $schema;
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            self::fail('could not create a backup directory');
        }
        if (file_put_contents($dir . DIRECTORY_SEPARATOR . '2026-08-01.zip', 'old') === false) {
            self::fail('could not write a zip');
        }
        putenv('STALL_BACKUP_DIR=' . $this->root);

        self::assertStringContainsString('No nightly copy', (new NightlyCopy())->headline($schema));
    }

    public function testAResolvedDatabaseVolumeIsNotUsed(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-nightly-' . bin2hex(random_bytes(4));
        $target = $this->root . DIRECTORY_SEPARATOR . 'perfect_consign_pg';
        $link = $this->root . DIRECTORY_SEPARATOR . 'alias';
        if (!mkdir($target, 0700, true) && !is_dir($target)) {
            self::fail('could not create a volume directory');
        }
        $schema = 'tenant_' . str_repeat('d', 32);
        $dir = $target . DIRECTORY_SEPARATOR . $schema;
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            self::fail('could not create a tenant directory');
        }
        if (file_put_contents($dir . DIRECTORY_SEPARATOR . '2026-07-04.zip', 'zip') === false) {
            self::fail('could not write a zip');
        }
        if (!self::linkDirectory($target, $link)) {
            self::fail('could not link the backup directory');
        }
        putenv('STALL_BACKUP_DIR=' . $link);

        self::assertStringContainsString('No nightly copy', (new NightlyCopy())->headline($schema));
        if (is_dir($link) && !rmdir($link)) {
            self::fail('could not remove the backup link');
        }
    }

    public function testTheDatabaseVolumeIsNotUsed(): void
    {
        putenv('STALL_BACKUP_DIR=C:\\data\\perfect_consign_pg\\backups');

        self::assertStringContainsString('No nightly copy', (new NightlyCopy())->headline('tenant_' . str_repeat('c', 32)));
    }

    private static function linkDirectory(string $target, string $link): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec('cmd /c mklink /J ' . escapeshellarg($link) . ' ' . escapeshellarg($target), $output, $code);

            return $code === 0 && is_dir($link);
        }

        return symlink($target, $link);
    }
}
