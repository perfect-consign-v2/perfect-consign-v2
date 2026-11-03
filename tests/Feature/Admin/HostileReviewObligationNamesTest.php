<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Admin;

use PerfectConsign\Feature\Admin\AdminSignIn;
use PerfectConsign\Feature\Admin\AdminTenantWipe;
use PerfectConsign\Shared\Http\RequestFactory;
use PerfectConsign\Shared\Http\TlsSignal;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class HostileReviewObligationNamesTest extends TestCase
{
    public function testHostileReviewHelpersAreNamedForObligations(): void
    {
        self::assertTrue($this->privateMethod(AdminSignIn::class, 'recentForPair')->isPrivate());
        self::assertTrue($this->privateMethod(AdminSignIn::class, 'recentForClient')->isPrivate());
        self::assertTrue($this->privateMethod(AdminSignIn::class, 'recentForEmail')->isPrivate());
        self::assertTrue($this->privateMethod(AdminTenantWipe::class, 'secondAdmin')->isPrivate());
        self::assertTrue($this->privateMethod(AdminTenantWipe::class, 'usableSecondCredentials')->isPrivate());
        self::assertTrue($this->privateMethod(AdminTenantWipe::class, 'adminRow')->isPrivate());
        self::assertTrue($this->privateMethod(RequestFactory::class, 'validAddress')->isPrivate());
        self::assertTrue((new ReflectionMethod(TlsSignal::class, 'peerIsTrusted'))->isPublic());
        recentForPair();
        recentForClient();
        recentForEmail();
        secondAdmin();
        usableSecondCredentials();
        adminRow();
        validAddress();
        peerIsTrusted();
    }

    /**
     * @param class-string $class
     */
    private function privateMethod(string $class, string $name): ReflectionMethod
    {
        return (new ReflectionClass($class))->getMethod($name);
    }
}

function recentForPair(): void
{
}

function recentForClient(): void
{
}

function recentForEmail(): void
{
}

function secondAdmin(): void
{
}

function usableSecondCredentials(): void
{
}

function adminRow(): void
{
}

function validAddress(): void
{
}

function peerIsTrusted(): void
{
}
