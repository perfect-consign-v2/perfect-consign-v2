<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\SignedInVisitor;
use PerfectConsign\Feature\Admin\AdminSession;
use PerfectConsign\Shared\Http\Request;
use PHPUnit\Framework\TestCase;

final class SignedInVisitorTest extends TestCase
{
    private const ACCOUNT = '01900000-0000-7000-8000-0000000000a1';

    public function testOnlyTheSessionCookieMakesSomeoneAPossibleSignIn(): void
    {
        $name = session_name();
        self::assertIsString($name);
        self::assertTrue(SignedInVisitor::hasCookie([$name => 'abc']));
        self::assertFalse(SignedInVisitor::hasCookie([$name => '']));
        self::assertFalse(SignedInVisitor::hasCookie([$name => ['abc']]));
        self::assertFalse(SignedInVisitor::hasCookie([]));
    }

    public function testAStoreSignInCounts(): void
    {
        $now = (string) time();
        self::assertTrue(SignedInVisitor::present($this->session([
            'owner_slug' => 'webprobe',
            'actor_role' => 'cashier',
            'signed_in_at' => $now,
            'last_seen_at' => $now,
        ]), $this->request('/wp-login.php')));
    }

    public function testAStaleOrEmptySessionDoesNotCount(): void
    {
        $old = (string) (time() - 90000);
        self::assertFalse(SignedInVisitor::present($this->session([
            'owner_slug' => 'webprobe',
            'actor_role' => 'owner',
            'signed_in_at' => $old,
            'last_seen_at' => $old,
        ]), $this->request('/')));
        self::assertFalse(SignedInVisitor::present($this->session(['csrf' => 'token']), $this->request('/')));
    }

    public function testAPlatformAdminCountsUntilTheSessionGoesStale(): void
    {
        $now = (string) time();
        self::assertTrue(SignedInVisitor::present($this->session([
            AdminSession::ID => self::ACCOUNT,
            AdminSession::EMAIL => 'admin@example.test',
            AdminSession::VERSION => '1',
            'platform_signed_in_at' => $now,
            'platform_last_seen_at' => $now,
        ]), $this->request('/admin')));
        $old = (string) (time() - 7200);
        self::assertFalse(SignedInVisitor::present($this->session([
            AdminSession::ID => self::ACCOUNT,
            AdminSession::EMAIL => 'admin@example.test',
            AdminSession::VERSION => '1',
            'platform_signed_in_at' => $old,
            'platform_last_seen_at' => $old,
        ]), $this->request('/admin')));
    }

    public function testAConsignorCountsOnlyOnTheirOwnStorePortal(): void
    {
        $session = $this->session(['consignor:webprobe' => self::ACCOUNT]);
        self::assertTrue(SignedInVisitor::present($session, $this->request('/webprobe/consignor')));
        self::assertTrue(SignedInVisitor::present($session, $this->request('/webprobe/consignor/preference')));
        self::assertFalse(SignedInVisitor::present($session, $this->request('/othermall/consignor')));
        self::assertFalse(SignedInVisitor::present($session, $this->request('/webprobe/consignors')));
        self::assertFalse(SignedInVisitor::present(
            $this->session(['consignor:webprobe' => 'not-a-vendor']),
            $this->request('/webprobe/consignor'),
        ));
    }

    private function request(string $path): Request
    {
        return new Request('GET', $path, [], []);
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
