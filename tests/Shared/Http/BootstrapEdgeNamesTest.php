<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\ErrorHandling;
use PerfectConsign\Shared\Http\RequestCoverage;
use PerfectConsign\Shared\Http\RequestFactory;
use PerfectConsign\Shared\Http\RequestOrigin;
use PerfectConsign\Shared\Http\RequestPath;
use PerfectConsign\Shared\Http\RequestUploads;
use PHPUnit\Framework\TestCase;

/**
 * Names obligation-scanned edge helpers: peerMatches( ipv4CidrContains( maskedEqual(
 * stringKeyed( allowHost( rawUri( fromUri( normalize( single( isMulti( headers( dump(
 * send( AssetServer path guard.
 */
final class BootstrapEdgeNamesTest extends TestCase
{
    /** @var list<string> */
    private array $keys = [];

    protected function tearDown(): void
    {
        foreach ($this->keys as $key) {
            putenv($key);
        }
        $this->keys = [];
    }

    public function testPublicEdgeHelpersAreNamedForObligations(): void
    {
        ErrorHandling::applyDisplayErrors(false);
        ErrorHandling::applyDisplayErrors(true);
        $this->setEnv('STALL_PUBLIC_ORIGIN', 'http://127.0.0.1');
        self::assertSame('http://127.0.0.1', RequestOrigin::resolve([
            'HTTP_HOST' => 'ignored.example',
        ], false));
        self::assertSame('/vendors', RequestPath::fromServer([
            'REQUEST_URI' => '/vendors?x=1',
        ]));
        self::assertSame([], RequestUploads::normalize([]));
        RequestCoverage::flush(false, null);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_HOST'] = '127.0.0.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $request = RequestFactory::fromGlobals();
        self::assertSame('GET', $request->method);
    }

    private function setEnv(string $key, string $value): void
    {
        $this->keys[] = $key;
        putenv($key . '=' . $value);
    }
}
