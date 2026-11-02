<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\RequestOrigin;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RequestOriginTest extends TestCase
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

    public function testConfiguredOriginIsReturned(): void
    {
        $this->setEnv('STALL_PUBLIC_ORIGIN', 'https://mall.example');
        self::assertSame('https://mall.example', RequestOrigin::resolve([
            'HTTP_HOST' => 'ignored.example',
        ], false));
    }

    public function testInvalidHostIsRefusedWhenHostOriginIsAllowed(): void
    {
        $this->setEnv('STALL_PUBLIC_ORIGIN', '');
        $this->setEnv('STALL_ALLOW_HOST_ORIGIN', 'true');
        $this->setEnv('APP_ENV', 'local');
        $this->setEnv('APP_DEBUG', '');
        $this->expectException(RuntimeException::class);
        RequestOrigin::resolve([
            'HTTP_HOST' => 'bad host!',
        ], false);
    }

    public function testNonStringHostIsRefused(): void
    {
        $this->setEnv('STALL_PUBLIC_ORIGIN', '');
        $this->setEnv('STALL_ALLOW_HOST_ORIGIN', 'true');
        $this->setEnv('APP_ENV', 'development');
        $this->expectException(RuntimeException::class);
        RequestOrigin::resolve([
            'HTTP_HOST' => 8096,
        ], true);
    }

    public function testAppDebugAllowsHostOriginOutsideNamedDevEnvs(): void
    {
        $this->setEnv('STALL_PUBLIC_ORIGIN', '');
        $this->setEnv('STALL_ALLOW_HOST_ORIGIN', 'true');
        $this->setEnv('APP_ENV', 'staging');
        $this->setEnv('APP_DEBUG', 'true');
        self::assertSame('https://mall.example', RequestOrigin::resolve([
            'HTTP_HOST' => 'mall.example',
        ], true));
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
