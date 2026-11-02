<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\RequestPath;
use PHPUnit\Framework\TestCase;

final class RequestPathTest extends TestCase
{
    public function testNonStringOrEmptyRequestUriFallsBackToRoot(): void
    {
        self::assertSame('/', RequestPath::fromServer([]));
        self::assertSame('/', RequestPath::fromServer(['REQUEST_URI' => '']));
        self::assertSame('/', RequestPath::fromServer(['REQUEST_URI' => 0]));
    }

    public function testQueryStringIsStripped(): void
    {
        self::assertSame('/vendors', RequestPath::fromServer([
            'REQUEST_URI' => '/vendors?sort=name',
        ]));
    }

    public function testAbsoluteUriKeepsPathOnly(): void
    {
        self::assertSame('/foo', RequestPath::fromServer([
            'REQUEST_URI' => 'http://evil.example/foo?x=1',
        ]));
        self::assertSame('/', RequestPath::fromServer([
            'REQUEST_URI' => 'https://evil.example',
        ]));
    }

    public function testTraversalAndDoubleSlashCollapseToRoot(): void
    {
        self::assertSame('/', RequestPath::fromServer([
            'REQUEST_URI' => '/../etc/passwd',
        ]));
        self::assertSame('/', RequestPath::fromServer([
            'REQUEST_URI' => '/pos//pay',
        ]));
    }

    public function testTrailingSlashIsTrimmedExceptRoot(): void
    {
        self::assertSame('/pos', RequestPath::fromServer([
            'REQUEST_URI' => '/pos/',
        ]));
        self::assertSame('/', RequestPath::fromServer([
            'REQUEST_URI' => '/',
        ]));
    }

    public function testProtocolRelativeTargetIsRoot(): void
    {
        self::assertSame('/', RequestPath::fromServer([
            'REQUEST_URI' => '//evil.example/foo',
        ]));
    }

    public function testRelativePathWithoutLeadingSlashIsRoot(): void
    {
        self::assertSame('/', RequestPath::fromServer([
            'REQUEST_URI' => 'relative',
        ]));
    }
}
