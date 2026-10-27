<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\DatabaseConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseConfigTest extends TestCase
{
    /** @var list<string> */
    private array $keys = [];

    protected function tearDown(): void
    {
        foreach ($this->keys as $key) {
            putenv($key);
        }
    }

    public function testValidConfigCastsPort(): void
    {
        $this->set('STALL_DB_HOST', '127.0.0.1');
        $this->set('STALL_DB_PORT', '5436');
        $this->set('STALL_DB_NAME', 'consign');
        $this->set('STALL_APP_PASSWORD', 'secret');
        $config = DatabaseConfig::fromEnv();
        self::assertSame(5436, $config['port']);
        self::assertSame('stall_app', $config['username']);
    }

    public function testSemicolonInHostIsRefused(): void
    {
        $this->set('STALL_DB_HOST', '127.0.0.1;dbname=other');
        $this->set('STALL_DB_PORT', '5436');
        $this->set('STALL_DB_NAME', 'consign');
        $this->set('STALL_APP_PASSWORD', 'secret');
        $this->expectException(RuntimeException::class);
        DatabaseConfig::fromEnv();
    }

    public function testNonNumericPortIsRefused(): void
    {
        $this->set('STALL_DB_HOST', '127.0.0.1');
        $this->set('STALL_DB_PORT', '54a6');
        $this->set('STALL_DB_NAME', 'consign');
        $this->set('STALL_APP_PASSWORD', 'secret');
        $this->expectException(RuntimeException::class);
        DatabaseConfig::fromEnv();
    }

    private function set(string $key, string $value): void
    {
        $this->keys[] = $key;
        putenv($key . '=' . $value);
    }
}
