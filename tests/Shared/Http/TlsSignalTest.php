<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\TlsSignal;
use PHPUnit\Framework\TestCase;

final class TlsSignalTest extends TestCase
{
    public function testSpoofedForwardedProtoFromUntrustedPeerIsIgnored(): void
    {
        self::assertFalse(TlsSignal::secure([
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], ''));
        self::assertFalse(TlsSignal::secure([
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], '10.0.0.1'));
    }

    public function testTrustedProxyHonorsLeftmostForwardedProto(): void
    {
        self::assertTrue(TlsSignal::secure([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https, http',
        ], '10.0.0.1'));
        self::assertFalse(TlsSignal::secure([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'http, https',
        ], '10.0.0.1'));
    }

    public function testCidrAllowlistMatches(): void
    {
        self::assertTrue(TlsSignal::secure([
            'REMOTE_ADDR' => '10.0.0.50',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], '10.0.0.0/24'));
        self::assertFalse(TlsSignal::secure([
            'REMOTE_ADDR' => '10.0.1.50',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], '10.0.0.0/24'));
    }

    public function testNativeHttpsWins(): void
    {
        self::assertTrue(TlsSignal::secure([
            'HTTPS' => 'on',
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'http',
        ], ''));
    }
}
