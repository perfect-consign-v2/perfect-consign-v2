<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\PriorClose;
use PerfectConsign\Shared\Http\Request;
use PHPUnit\Framework\TestCase;

final class ActorPathTest extends TestCase
{
    public function testEachRoleHasAHomePage(): void
    {
        self::assertSame('/demo/pos', $this->actor(['actor_role' => 'cashier'])->path('demo'));
        self::assertSame('/demo/vendor', $this->actor(['actor_role' => 'vendor'])->path('demo'));
        self::assertSame('/demo/sales', $this->actor(['actor_role' => 'books'])->path('demo'));
        self::assertSame('/demo/dashboard', $this->actor(['actor_role' => 'owner'])->path('demo'));
        $withTenant = $this->actor([
            'owner_slug' => 'demo',
            'tenant_id' => '01900000-0000-7000-8000-0000000000a1',
            'actor_role' => 'owner',
            'signed_in_at' => (string) time(),
            'last_seen_at' => (string) time(),
        ]);
        self::assertSame('demo', $withTenant->slug());
        self::assertSame('01900000-0000-7000-8000-0000000000a1', $withTenant->tenantId());
        self::assertNull($this->actor(['owner_slug' => 'demo', 'actor_role' => ''])->slug());
        $stale = $this->actor([
            'owner_slug' => 'demo',
            'actor_role' => 'owner',
            'signed_in_at' => (string) (time() - 90000),
            'last_seen_at' => (string) time(),
        ]);
        self::assertNull($stale->slug());
        self::assertNull($this->actor([
            'owner_slug' => '',
            'actor_role' => 'owner',
            'signed_in_at' => (string) time(),
            'last_seen_at' => (string) time(),
        ])->slug());
        $now = time();
        self::assertNull($this->actor([
            'owner_slug' => 'demo',
            'actor_role' => 'owner',
            'signed_in_at' => (string) ($now - 86400),
            'last_seen_at' => (string) $now,
        ])->slug());
    }

    public function testAPriorCloseOnTheRegisterPageStaysPut(): void
    {
        $session = $this->session([]);
        $close = new PriorClose($session, new Request('GET', '/demo/pos', [], []));
        $left = [['id' => '01900000-0000-7000-8000-000000000d10', 'name' => 'Front', 'opened' => 'Oct 5']];
        self::assertNull($close->redirect('demo', []));
        self::assertNull($close->redirect('demo', $left));
        $tape = new PriorClose($session, new Request('GET', '/demo/registers/01900000-0000-7000-8000-000000000d10/shifts', [], []));
        self::assertNull($tape->redirect('demo', $left));
        $closing = new PriorClose(
            $session,
            new Request('GET', '/demo/registers/01900000-0000-7000-8000-000000000d10/close', [], [])
        );
        self::assertNull($closing->redirect('demo', $left));
        $session->set('close_prior:demo', 'yes');
        $away = new PriorClose($session, new Request('GET', '/demo/dashboard', [], []));
        self::assertSame('/demo/registers?close=01900000-0000-7000-8000-000000000d10', $away->redirect('demo', $left));
        $query = new PriorClose($session, new Request('GET', '/demo/registers', ['close' => '01900000-0000-7000-8000-000000000d10'], []));
        self::assertNull($query->redirect('demo', $left));
    }

    /**
     * @param array<string, mixed> $bag
     */
    private function actor(array $bag): Actor
    {
        return new Actor($this->session($bag));
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

            public function destroy(): void
            {
                $this->bag = [];
            }
        };
    }
}
