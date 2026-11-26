<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use Closure;
use RuntimeException;

/**
 * Makes a file fail to open, read, or be replaced, in the way this system refuses it.
 *
 * Windows refuses to open a directory as a file and refuses to replace a read-only file.
 * Linux and macOS open a directory and replace a read-only file, but refuse a file whose
 * mode denies reading and refuse any change inside a directory that denies writing.
 * Each method returns null when this system cannot refuse at all (for example, tests run as root).
 */
final class FileFault
{
    /**
     * A path that fopen($path, 'rb') and file_get_contents($path) refuse.
     * Pass the path back to release() when the test is done.
     */
    public static function unopenable(string $directory): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $directory;
        }
        $path = $directory . DIRECTORY_SEPARATOR . 'unopenable-' . bin2hex(random_bytes(4));
        if (file_put_contents($path, 'x') === false) {
            throw new RuntimeException('the unopenable file could not be created');
        }
        $undo = self::denyRead($path);
        if ($undo === null) {
            unlink($path);

            return null;
        }

        return $path;
    }

    public static function release(?string $path): void
    {
        if ($path === null || PHP_OS_FAMILY === 'Windows' || !is_file($path)) {
            return;
        }
        chmod($path, 0600);
        unlink($path);
    }

    /**
     * Deny reading an existing file. The closure gives reading back.
     */
    public static function denyRead(string $file): ?Closure
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $ran = shell_exec('icacls ' . escapeshellarg($file) . ' /deny Everyone:(R)');
            if (!is_string($ran)) {
                return null;
            }

            return static function () use ($file): void {
                shell_exec('icacls ' . escapeshellarg($file) . ' /remove:d Everyone');
            };
        }
        chmod($file, 0000);
        clearstatcache(true, $file);
        $undo = static function () use ($file): void {
            chmod($file, 0600);
            clearstatcache(true, $file);
        };
        if (is_readable($file)) {
            $undo();

            return null;
        }

        return $undo;
    }

    /**
     * Refuse replacing or removing an existing file. The closure allows it again.
     */
    public static function lockReplace(string $file): ?Closure
    {
        if (PHP_OS_FAMILY === 'Windows') {
            chmod($file, 0444);

            return static function () use ($file): void {
                chmod($file, 0666);
            };
        }
        $parent = dirname($file);
        $mode = fileperms($parent);
        if ($mode === false) {
            throw new RuntimeException('the parent directory mode could not be read');
        }
        chmod($parent, 0555);
        clearstatcache(true, $parent);
        $undo = static function () use ($parent, $mode): void {
            chmod($parent, $mode & 0777);
            clearstatcache(true, $parent);
        };
        if (is_writable($parent)) {
            $undo();

            return null;
        }

        return $undo;
    }
}
