<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\FrontDoor;
use PerfectConsign\Bootstrap\MallChrome;
use PerfectConsign\Bootstrap\PriorClose;
use PerfectConsign\Shared\Http\Request;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class MallChromeTest extends TestCase
{
    public function testAVendorDoesNotSeeAPriorClosePrompt(): void
    {
        $session = $this->createStub(Session::class);
        $session->method('get')->willReturnCallback(static function (string $key): mixed {
            return $key === 'actor_role' ? 'vendor' : null;
        });
        $deleted = [];
        $session->method('delete')->willReturnCallback(static function (string $key) use (&$deleted): void {
            $deleted[] = $key;
        });
        $chrome = $this->chrome($session, new Request('GET', '/demo/vendor', [], []));
        $data = [];
        self::assertNull($chrome->away($data, 'demo', false));
        self::assertSame(['close_prior:demo'], $deleted);
        self::assertFalse($data['priorPrompt']);
    }

    public function testRentPagesSendCashiersToPos(): void
    {
        $chrome = $this->chrome($this->role('cashier'), new Request('GET', '/demo/rent', [], []));
        self::assertSame('/demo/pos', $chrome->rentHome('demo', true, false));
        $books = $this->chrome($this->role('books'), new Request('GET', '/demo/booths/new', [], []));
        self::assertSame('/demo/sales', $books->rentHome('demo', true, false));
        $owner = $this->chrome($this->role('owner'), new Request('GET', '/demo/map', [], []));
        self::assertSame('/demo/dashboard', $owner->rentHome('demo', true, false));
        self::assertNull($owner->rentHome('demo', true, true));
        self::assertNull($owner->rentHome('demo', false, false));
        $other = $this->chrome($this->role('owner'), new Request('GET', '/demo/pos', [], []));
        self::assertNull($other->rentHome('demo', true, false));
        $outside = $this->chrome($this->role('owner'), new Request('GET', '/other/rent', [], []));
        self::assertNull($outside->rentHome('demo', true, false));
    }

    public function testOwnerPreviewTokensAreKeptOnTheVendorPortal(): void
    {
        $as = '01900000-0000-7000-8000-0000000000aa';
        $chrome = $this->chrome($this->role('owner'), new Request('GET', '/demo/vendor', ['as' => $as], []));
        self::assertSame($as, $chrome->previewAs('demo'));
        self::assertSame('', $chrome->previewAs(''));
        $cashier = $this->chrome($this->role('cashier'), new Request('GET', '/demo/vendor', ['as' => $as], []));
        self::assertSame('', $cashier->previewAs('demo'));
        $off = $this->chrome($this->role('owner'), new Request('GET', '/demo/pos', ['as' => $as], []));
        self::assertSame('', $off->previewAs('demo'));
        $data = [];
        self::assertNull($chrome->away($data, 'demo', false));
        self::assertFalse($data['priorPrompt']);
    }

    public function testFlashValuesAreStoredAndTakenOnce(): void
    {
        $bag = [];
        $session = $this->createStub(Session::class);
        $session->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$bag): void {
            $bag[$key] = $value;
        });
        $session->method('get')->willReturnCallback(static function (string $key) use (&$bag): mixed {
            return $bag[$key] ?? null;
        });
        $session->method('has')->willReturnCallback(static function (string $key) use (&$bag): bool {
            return array_key_exists($key, $bag);
        });
        $session->method('delete')->willReturnCallback(static function (string $key) use (&$bag): void {
            unset($bag[$key]);
        });
        $chrome = $this->chrome($session, new Request('GET', '/demo/registers', [], []));
        $chrome->keepFlash('notice', '');
        $chrome->keepFlash('notice', null);
        self::assertArrayNotHasKey('notice', $bag);
        $chrome->keepFlash('notice', 'Saved.');
        self::assertSame('Saved.', $chrome->takeFlash('notice'));
        self::assertNull($chrome->takeFlash('notice'));
        $bag['warning'] = 1;
        self::assertNull($chrome->takeFlash('warning'));
    }

    public function testAPriorShiftOnTheHubDoesNotPrompt(): void
    {
        $session = $this->role('owner');
        $chrome = $this->chrome($session, new Request('GET', '/demo/registers', [], []));
        $data = [];
        $chrome->away($data, 'demo', false);
        self::assertFalse($data['priorPrompt']);
        self::assertFalse($data['priorCloseStarted']);
    }

    private function role(string $role): Session
    {
        $session = $this->createStub(Session::class);
        $session->method('get')->willReturnCallback(static function (string $key) use ($role): mixed {
            return $key === 'actor_role' ? $role : null;
        });

        return $session;
    }

    private function chrome(Session $session, Request $request): MallChrome
    {
        $actor = new Actor($session);
        $door = (new ReflectionClass(FrontDoor::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(FrontDoor::class, 'actor'))->setValue($door, $actor);
        (new ReflectionProperty(FrontDoor::class, 'prior'))->setValue($door, new PriorClose($session, $request));
        $drawers = (new ReflectionClass(ActiveRegister::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(ActiveRegister::class, 'session'))->setValue($drawers, $session);
        (new ReflectionProperty(ActiveRegister::class, 'pdo'))->setValue($drawers, $this->createStub(\PDO::class));

        return new MallChrome($drawers, $session, $door, $request);
    }
}
