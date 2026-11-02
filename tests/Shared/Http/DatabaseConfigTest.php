<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\DatabaseConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseConfigTest extends TestCase
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

    public function testMissingPasswordIsRefused(): void
    {
        $this->set('STALL_DB_HOST', '127.0.0.1');
        $this->set('STALL_DB_PORT', '5436');
        $this->set('STALL_DB_NAME', 'consign');
        putenv('STALL_APP_PASSWORD');
        $this->expectException(RuntimeException::class);
        DatabaseConfig::fromEnv();
    }

    public function testEmptyPasswordIsRefused(): void
    {
        $this->set('STALL_DB_HOST', '127.0.0.1');
        $this->set('STALL_DB_PORT', '5436');
        $this->set('STALL_DB_NAME', 'consign');
        $this->set('STALL_APP_PASSWORD', '');
        $this->expectException(RuntimeException::class);
        DatabaseConfig::fromEnv();
    }

    public function testEmptyHostAndNameEnvValuesUseDefaults(): void
    {
        $this->set('STALL_DB_HOST', '');
        $this->set('STALL_DB_PORT', '5436');
        $this->set('STALL_DB_NAME', '');
        $this->set('STALL_APP_PASSWORD', 'secret');
        $config = DatabaseConfig::fromEnv();
        self::assertSame('127.0.0.1', $config['host']);
        self::assertSame('consign', $config['dbname']);
    }

    public function testSemicolonInDbNameIsRefused(): void
    {
        $this->set('STALL_DB_HOST', '127.0.0.1');
        $this->set('STALL_DB_PORT', '5436');
        $this->set('STALL_DB_NAME', 'consign;drop');
        $this->set('STALL_APP_PASSWORD', 'secret');
        $this->expectException(RuntimeException::class);
        DatabaseConfig::fromEnv();
    }

    public function testZeroPortIsRefused(): void
    {
        $this->set('STALL_DB_HOST', '127.0.0.1');
        $this->set('STALL_DB_NAME', 'consign');
        $this->set('STALL_APP_PASSWORD', 'secret');
        $this->set('STALL_DB_PORT', '0');
        $this->expectException(RuntimeException::class);
        DatabaseConfig::fromEnv();
    }

    public function testPortAboveMaxIsRefused(): void
    {
        $this->set('STALL_DB_HOST', '127.0.0.1');
        $this->set('STALL_DB_NAME', 'consign');
        $this->set('STALL_APP_PASSWORD', 'secret');
        $this->set('STALL_DB_PORT', '65536');
        $this->expectException(RuntimeException::class);
        DatabaseConfig::fromEnv();
    }

    public function testMinAndMaxPortsAreAccepted(): void
    {
        $this->set('STALL_DB_HOST', '127.0.0.1');
        $this->set('STALL_DB_NAME', 'consign');
        $this->set('STALL_APP_PASSWORD', 'secret');
        $this->set('STALL_DB_PORT', '1');
        self::assertSame(1, DatabaseConfig::fromEnv()['port']);
        $this->set('STALL_DB_PORT', '65535');
        self::assertSame(65535, DatabaseConfig::fromEnv()['port']);
    }

    private function set(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->previous)) {
            $current = getenv($key);
            if ((!is_string($current) || $current === '') && isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
                $current = $_SERVER[$key];
            }
            $this->previous[$key] = $current;
        }
        putenv($key . '=' . $value);
    }
}
