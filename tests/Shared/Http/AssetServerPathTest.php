<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\AssetServer;
use PerfectConsign\Tests\Support\WebTestCase;
use ReflectionMethod;

final class AssetServerPathTest extends WebTestCase
{
    public function testAppCssResolvesUnderPublicAssets(): void
    {
        $resolved = $this->resolvedFile('/assets/app.css');
        $expected = realpath(dirname(__DIR__, 3) . '/public/assets/app.css');
        self::assertNotFalse($expected);
        self::assertSame($expected, $resolved);
    }

    public function testAppJsResolvesUnderPublicAssets(): void
    {
        $resolved = $this->resolvedFile('/assets/js/app.js');
        $expected = realpath(dirname(__DIR__, 3) . '/public/assets/js/app.js');
        self::assertNotFalse($expected);
        self::assertSame($expected, $resolved);
    }

    public function testPathTraversalOutsideAssetsIsRefused(): void
    {
        self::assertNull($this->resolvedFile('/assets/../.env'));
        self::assertNull($this->resolvedFile('/assets/../../composer.json'));
    }

    public function testHttpServesAppCss(): void
    {
        $page = self::client()->get('/assets/app.css');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Ninja EMP', $page->body);
        self::assertStringNotContainsString('Not found', $page->body);
    }

    public function testHttpServesAppJs(): void
    {
        $page = self::client()->get('/assets/js/app.js');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Ninja EMP', $page->body);
        self::assertStringNotContainsString('Not found', $page->body);
    }

    public function testLocalEnvRefusesAssetCaching(): void
    {
        $previous = getenv('APP_ENV');
        try {
            putenv('APP_ENV=local');
            self::assertSame('no-store', $this->cacheControl());
            putenv('APP_ENV=development');
            self::assertSame('no-store', $this->cacheControl());
            putenv('APP_ENV=dev');
            self::assertSame('no-store', $this->cacheControl());
            putenv('APP_ENV=production');
            self::assertSame('public, max-age=86400', $this->cacheControl());
            putenv('APP_ENV=test');
            self::assertSame('public, max-age=86400', $this->cacheControl());
        } finally {
            if ($previous === false) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV=' . $previous);
            }
        }
    }

    private function resolvedFile(string $path): ?string
    {
        $method = new ReflectionMethod(AssetServer::class, 'resolvedFile');

        $result = $method->invoke(null, $path);
        self::assertTrue($result === null || is_string($result));

        return $result;
    }

    private function cacheControl(): string
    {
        $method = new ReflectionMethod(AssetServer::class, 'cacheControl');
        $result = $method->invoke(null);
        self::assertIsString($result);

        return $result;
    }
}
