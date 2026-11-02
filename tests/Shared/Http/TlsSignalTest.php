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
        self::assertFalse(TlsSignal::secure([
            'REMOTE_ADDR' => 203,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], '10.0.0.1'));
        self::assertFalse(TlsSignal::secure([
            'REMOTE_ADDR' => '',
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
        self::assertFalse(TlsSignal::secure([
            'HTTPS' => 'off',
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], ''));
    }

    public function testEmptyForwardedProtoFromTrustedPeerIsIgnored(): void
    {
        self::assertFalse(TlsSignal::secure([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => '',
        ], '10.0.0.1'));
        self::assertFalse(TlsSignal::secure([
            'REMOTE_ADDR' => '10.0.0.1',
        ], '10.0.0.1'));
    }

    public function testInvalidCidrMaskIsRefused(): void
    {
        self::assertFalse(TlsSignal::secure([
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], '10.0.0.0/33'));
        self::assertFalse(TlsSignal::secure([
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], '10.0.0.0/-1'));
        self::assertFalse(TlsSignal::secure([
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], '10.0.0.0'));
    }

    public function testCidrBoundaryMasks(): void
    {
        self::assertTrue(TlsSignal::secure([
            'REMOTE_ADDR' => '203.0.113.44',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], '203.0.113.44/32'));
        self::assertFalse(TlsSignal::secure([
            'REMOTE_ADDR' => '203.0.113.45',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], '203.0.113.44/32'));
        self::assertTrue(TlsSignal::secure([
            'REMOTE_ADDR' => '198.51.100.9',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], '0.0.0.0/0'));
    }

    public function testIpv6PeerDoesNotMatchIpv4Cidr(): void
    {
        self::assertFalse(TlsSignal::secure([
            'REMOTE_ADDR' => '::1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], '10.0.0.0/8'));
    }

    public function testWhitespaceOnlyAllowlistIsUntrusted(): void
    {
        self::assertFalse(TlsSignal::secure([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], '   '));
    }
}
