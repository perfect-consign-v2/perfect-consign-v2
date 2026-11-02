<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\SameHostReferer;
use PHPUnit\Framework\TestCase;

final class SameHostRefererTest extends TestCase
{
    public function testMissingOrBlankRefererOrHostYieldsEmpty(): void
    {
        self::assertSame('', SameHostReferer::path([], false));
        self::assertSame('', SameHostReferer::path([
            'HTTP_REFERER' => 'https://mall.example/pos',
            'HTTP_HOST' => '',
        ], false));
        self::assertSame('', SameHostReferer::path([
            'HTTP_REFERER' => '',
            'HTTP_HOST' => 'mall.example',
        ], false));
        self::assertSame('', SameHostReferer::path([
            'HTTP_REFERER' => 1,
            'HTTP_HOST' => 'mall.example',
        ], false));
        self::assertSame('', SameHostReferer::path([
            'HTTP_REFERER' => 'https://mall.example/pos',
            'HTTP_HOST' => 1,
        ], false));
    }

    public function testOverlongRefererIsRefused(): void
    {
        self::assertSame('', SameHostReferer::path([
            'HTTP_REFERER' => 'https://mall.example/' . str_repeat('a', 2048),
            'HTTP_HOST' => 'mall.example',
        ], false));
    }

    public function testRefererAtMaxLengthIsAccepted(): void
    {
        $prefix = 'https://mall.example/';
        $suffix = str_repeat('a', 2048 - strlen($prefix));
        $referer = $prefix . $suffix;
        self::assertSame(2048, strlen($referer));
        self::assertSame('/' . $suffix, SameHostReferer::path([
            'HTTP_REFERER' => $referer,
            'HTTP_HOST' => 'mall.example',
        ], true));
    }

    public function testUnparseableRefererIsRefused(): void
    {
        self::assertSame('', SameHostReferer::path([
            'HTTP_REFERER' => 'http://',
            'HTTP_HOST' => 'mall.example',
        ], true));
    }

    public function testForeignHostAndSchemeMismatchAreRefused(): void
    {
        self::assertSame('', SameHostReferer::path([
            'HTTP_REFERER' => 'https://evil.example/pos',
            'HTTP_HOST' => 'mall.example',
        ], true));
        self::assertSame('', SameHostReferer::path([
            'HTTP_REFERER' => 'http://mall.example/pos',
            'HTTP_HOST' => 'mall.example',
        ], true));
        self::assertSame('', SameHostReferer::path([
            'HTTP_REFERER' => 'ftp://mall.example/pos',
            'HTTP_HOST' => 'mall.example',
        ], false));
    }

    public function testSameHostHttpsPathIsReturned(): void
    {
        self::assertSame('/pos/pay', SameHostReferer::path([
            'HTTP_REFERER' => 'https://mall.example/pos/pay',
            'HTTP_HOST' => 'mall.example:443',
        ], true));
        self::assertSame('/pos', SameHostReferer::path([
            'HTTP_REFERER' => 'http://mall.example/pos',
            'HTTP_HOST' => 'mall.example',
        ], false));
    }

    public function testProtocolRelativeAndEmptyPathsAreRefused(): void
    {
        self::assertSame('', SameHostReferer::path([
            'HTTP_REFERER' => 'https://mall.example',
            'HTTP_HOST' => 'mall.example',
        ], true));
        self::assertSame('', SameHostReferer::path([
            'HTTP_REFERER' => 'https://mall.example//evil',
            'HTTP_HOST' => 'mall.example',
        ], true));
    }
}
