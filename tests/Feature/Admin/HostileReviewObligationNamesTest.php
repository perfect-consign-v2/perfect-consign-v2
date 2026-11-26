<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Admin;

use PerfectConsign\Bootstrap\SignInGate;
use PerfectConsign\Feature\Admin\AdminSignIn;
use PerfectConsign\Feature\Admin\AdminTenantWipe;
use PerfectConsign\Shared\Http\RequestFactory;
use PerfectConsign\Shared\Http\TlsSignal;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class HostileReviewObligationNamesTest extends TestCase
{
    public function testHostileReviewHelpersStayPrivateOrPublicAsShipped(): void
    {
        self::assertTrue($this->method(AdminSignIn::class, 'lookup')->isPrivate());
        self::assertTrue($this->method(SignInGate::class, 'begin')->isPrivate());
        self::assertTrue($this->method(SignInGate::class, 'finish')->isPrivate());
        self::assertTrue($this->method(AdminTenantWipe::class, 'secondAdmin')->isPrivate());
        self::assertTrue($this->method(AdminTenantWipe::class, 'usableSecondCredentials')->isPrivate());
        self::assertTrue($this->method(AdminTenantWipe::class, 'adminRow')->isPrivate());
        self::assertTrue($this->method(RequestFactory::class, 'validAddress')->isPrivate());
        self::assertTrue($this->method(TlsSignal::class, 'peerIsTrusted')->isPublic());
    }

    /**
     * @param class-string $class
     */
    private function method(string $class, string $name): ReflectionMethod
    {
        return (new ReflectionClass($class))->getMethod($name);
    }
}
