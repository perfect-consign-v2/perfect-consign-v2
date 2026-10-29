<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Admin;

use PerfectConsign\Feature\Admin\AppLogFile;
use PHPUnit\Framework\TestCase;

final class AppLogFileTest extends TestCase
{
    public function testSnapshotAndClearRoundTrip(): void
    {
        $log = new AppLogFile();
        $path = $log->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            self::fail('could not create log directory');
        }
        $prior = is_file($path) ? file_get_contents($path) : false;
        $marker = 'app-log-proof-' . bin2hex(random_bytes(8));
        try {
            self::assertNotFalse(file_put_contents($path, $marker . "\n", LOCK_EX));
            $snapshot = $log->snapshot();
            self::assertTrue($snapshot['exists']);
            self::assertSame('var/log/app.log', $snapshot['path']);
            self::assertStringContainsString($marker, $snapshot['body']);
            self::assertGreaterThan(0, $snapshot['bytes']);
            $log->clear();
            self::assertSame(0, $log->byteCount());
            $empty = $log->snapshot();
            self::assertSame('', $empty['body']);
            self::assertSame(0, $empty['bytes']);
        } finally {
            if ($prior === false) {
                if (is_file($path)) {
                    unlink($path);
                }
            } else {
                file_put_contents($path, $prior, LOCK_EX);
            }
        }
    }

    public function testSnapshotOmitsPartialFirstLineWhenTruncated(): void
    {
        $log = new AppLogFile();
        $path = $log->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            self::fail('could not create log directory');
        }
        $prior = is_file($path) ? file_get_contents($path) : false;
        try {
            $payload = str_repeat('A', 120) . "\nKEEP-ME\n";
            self::assertNotFalse(file_put_contents($path, $payload, LOCK_EX));
            $snapshot = $log->snapshot(80);
            self::assertTrue($snapshot['truncated']);
            self::assertStringContainsString('KEEP-ME', $snapshot['body']);
            self::assertStringNotContainsString(str_repeat('A', 120), $snapshot['body']);
        } finally {
            if ($prior === false) {
                if (is_file($path)) {
                    unlink($path);
                }
            } else {
                file_put_contents($path, $prior, LOCK_EX);
            }
        }
    }
}
