<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\RequestFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RequestFactoryTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $previous = [];

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        $this->previous = [];
    }

    public function testConfiguredOriginWinsOverHost(): void
    {
        $this->setEnv('STALL_PUBLIC_ORIGIN', 'https://mall.example');
        $this->setEnv('STALL_ALLOW_HOST_ORIGIN', 'true');
        $this->setEnv('APP_ENV', 'local');
        $this->setEnv('APP_DEBUG', 'true');
        $request = RequestFactory::from([
            'REQUEST_METHOD' => 'get',
            'REQUEST_URI' => '/admin/forgot',
            'HTTP_HOST' => 'evil.example',
            'HTTPS' => '',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_ACCEPT' => 'text/html',
        ], [], [], [], '');
        self::assertSame('https://mall.example', $request->origin);
        self::assertSame('GET', $request->method);
    }

    public function testMissingOriginWithoutHostAllowThrows(): void
    {
        $this->setEnv('STALL_PUBLIC_ORIGIN', '');
        $this->setEnv('STALL_ALLOW_HOST_ORIGIN', '');
        $this->setEnv('APP_ENV', 'local');
        $this->setEnv('APP_DEBUG', 'true');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STALL_PUBLIC_ORIGIN is required');
        RequestFactory::from([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'localhost',
            'REMOTE_ADDR' => '127.0.0.1',
        ], [], [], [], '');
    }

    public function testHostOriginRequiresExplicitAllow(): void
    {
        $this->setEnv('STALL_PUBLIC_ORIGIN', '');
        $this->setEnv('STALL_ALLOW_HOST_ORIGIN', 'true');
        $this->setEnv('APP_ENV', 'local');
        $this->setEnv('APP_DEBUG', '');
        $request = RequestFactory::from([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => '127.0.0.1:8096',
            'REMOTE_ADDR' => '127.0.0.1',
        ], [], [], [], '');
        self::assertSame('http://127.0.0.1:8096', $request->origin);
    }

    public function testProductionRefusesHostOriginEvenWhenAllowed(): void
    {
        $this->setEnv('STALL_PUBLIC_ORIGIN', '');
        $this->setEnv('STALL_ALLOW_HOST_ORIGIN', 'true');
        $this->setEnv('APP_ENV', 'production');
        $this->setEnv('APP_DEBUG', 'true');
        $this->expectException(RuntimeException::class);
        RequestFactory::from([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'evil.example',
            'REMOTE_ADDR' => '127.0.0.1',
        ], [], [], [], '');
    }

    public function testMultiFileUploadFieldIsSkipped(): void
    {
        $this->setEnv('STALL_PUBLIC_ORIGIN', 'http://127.0.0.1');
        $request = RequestFactory::from([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/upload',
            'HTTP_HOST' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
        ], [], [], [
            'photos' => [
                'tmp_name' => ['/tmp/a', '/tmp/b'],
                'error' => [0, 0],
                'size' => [1, 2],
                'name' => ['a.jpg', 'b.jpg'],
            ],
            'single' => [
                'tmp_name' => '/tmp/c',
                'error' => 0,
                'size' => 3,
                'name' => 'c.jpg',
            ],
        ], '');
        self::assertArrayNotHasKey('photos', $request->uploads);
        self::assertArrayHasKey('single', $request->uploads);
    }

    public function testProtocolRelativeAndAbsoluteUriRequestTargetsAreNormalized(): void
    {
        $this->setEnv('STALL_PUBLIC_ORIGIN', 'http://127.0.0.1');
        $relative = RequestFactory::from([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '//evil.example/foo',
            'HTTP_HOST' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
        ], [], [], [], '');
        self::assertSame('/', $relative->path);
        $absolute = RequestFactory::from([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => 'http://evil.example/foo',
            'HTTP_HOST' => '127.0.0.1',
            'REMOTE_ADDR' => '127.0.0.1',
        ], [], [], [], '');
        self::assertSame('/foo', $absolute->path);
    }

    public function testHttpsRequestRejectsHttpRefererPath(): void
    {
        $this->setEnv('STALL_PUBLIC_ORIGIN', 'https://mall.example');
        $request = RequestFactory::from([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/x',
            'HTTP_HOST' => 'mall.example',
            'HTTPS' => 'on',
            'HTTP_REFERER' => 'http://mall.example/inventory',
            'REMOTE_ADDR' => '127.0.0.1',
        ], [], [], [], '');
        self::assertSame('', $request->referer);
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->previous)) {
            $this->previous[$key] = getenv($key);
        }
        if ($value === '') {
            putenv($key);

            return;
        }
        putenv($key . '=' . $value);
    }
}
