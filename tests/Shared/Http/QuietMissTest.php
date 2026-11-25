<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\QuietMiss;
use PHPUnit\Framework\TestCase;

final class QuietMissTest extends TestCase
{
    public function testFilesBrowsersAndPhonesAskForOnTheirOwnAreQuiet(): void
    {
        foreach (
            [
                '/.well-known',
                '/.well-known/change-password',
                '/.well-known/appspecific/com.chrome.devtools.json',
                '/apple-touch-icon-120x120.png',
                '/apple-touch-icon-152x152-precomposed.png',
                '/favicon-32x32.png',
                '/favicon.svg',
                '/site.webmanifest',
                '/manifest.json',
                '/manifest.webmanifest',
                '/browserconfig.xml',
            ] as $path
        ) {
            self::assertTrue(QuietMiss::matches($path), $path);
        }
    }

    public function testProbesAndStorePagesAreNotQuiet(): void
    {
        foreach (
            [
                '/',
                '/admin',
                '/wp-login.php',
                '/.env',
                '/.well-knownx',
                '/apple-touch-icon.php',
                '/favicon.php',
                '/webprobe/activity.csv',
                '/webprobe/manifest.json',
            ] as $path
        ) {
            self::assertFalse(QuietMiss::matches($path), $path);
        }
    }
}
