<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\ErrorHandling;
use PHPUnit\Framework\TestCase;

final class ErrorHandlingDisclosureTest extends TestCase
{
    public function testProductionHidesExceptionText(): void
    {
        self::assertFalse(ErrorHandling::exposeDetails('production', 'true'));
        self::assertFalse(ErrorHandling::exposeDetails('prod', 'true'));
        self::assertFalse(ErrorHandling::exposeDetails('production', false));
    }

    public function testLocalAndDebugStillExposeExceptionText(): void
    {
        self::assertTrue(ErrorHandling::exposeDetails('local', 'false'));
        self::assertTrue(ErrorHandling::exposeDetails('development', false));
        self::assertTrue(ErrorHandling::exposeDetails('dev', ''));
        self::assertTrue(ErrorHandling::exposeDetails('test', 'true'));
        self::assertTrue(ErrorHandling::exposeDetails('coverage', 'true'));
        self::assertFalse(ErrorHandling::exposeDetails('test', 'false'));
        self::assertFalse(ErrorHandling::exposeDetails('coverage', false));
    }
}
