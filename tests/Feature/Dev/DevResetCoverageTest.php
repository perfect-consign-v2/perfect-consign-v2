<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Dev;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Feature\Dev\DevResetController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class DevResetCoverageTest extends TestCase
{
    public function testOnlyTheOwnerOfTheDemoMallsMayResetThem(): void
    {
        $allowed = new ReflectionMethod(DevResetController::class, 'allowed');
        self::assertTrue($allowed->invoke($this->controller($this->actor('owner', 'mall')), 'mall'));
        self::assertFalse($allowed->invoke($this->controller($this->actor('owner', 'mall')), 'webprobe'));
        self::assertFalse($allowed->invoke($this->controller($this->actor('books', 'mall')), 'mall'));
        self::assertTrue($allowed->invoke($this->controller($this->actor('owner', 'dev')), 'dev'));
    }

    public function testAResetClearsTheOpenRegisterTape(): void
    {
        $reset = $this->controller($this->actor('owner', 'mall'));
        $session = $this->session([
            'register:mall' => 'open',
            'register_name:mall' => 'Front',
            'sale:mall' => '1',
            'sale_tendered:mall' => '1',
            'close_prior:mall' => 'yes',
        ]);
        (new ReflectionProperty(DevResetController::class, 'session'))->setValue($reset, $session);
        (new ReflectionMethod(DevResetController::class, 'clearMall'))->invoke($reset, 'mall');
        self::assertNull($session->get('register:mall'));
        self::assertNull($session->get('close_prior:mall'));
    }

    private function controller(Actor $actor): DevResetController
    {
        $reset = (new ReflectionClass(DevResetController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(DevResetController::class, 'actor'))->setValue($reset, $actor);

        return $reset;
    }

    private function actor(string $role, string $slug): Actor
    {
        $now = (string) time();

        return new Actor($this->session([
            'actor_role' => $role,
            'owner_slug' => $slug,
            'signed_in_at' => $now,
            'last_seen_at' => $now,
        ]));
    }

    /**
     * @param array<string, mixed> $bag
     */
    private function session(array $bag): Session
    {
        return new class ($bag) extends Session {
            /** @param array<string, mixed> $bag */
            public function __construct(private array $bag)
            {
            }

            public function get(string $key): mixed
            {
                return $this->bag[$key] ?? null;
            }

            public function set(string $key, mixed $value): void
            {
                $this->bag[$key] = $value;
            }

            public function delete(string $key): void
            {
                unset($this->bag[$key]);
            }
        };
    }
}
