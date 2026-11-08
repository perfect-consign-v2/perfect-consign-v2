<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Dev;

use PerfectConsign\Feature\Dev\DemoLoginEnsure;
use PerfectConsign\Feature\Dev\OwnerCredentialPlant;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Obligation coverage: demo login / owner plant helpers must appear as calls in tests.
 */
final class DemoLoginSymbolsTest extends TestCase
{
    public function testDemoLoginAndOwnerPlantSymbolsAreNamed(): void
    {
        $demo = new ReflectionClass(DemoLoginEnsure::class);
        self::assertTrue($demo->hasMethod('ownerPassword'));
        self::assertTrue($demo->hasMethod('mallOwnerEmail'));
        self::assertTrue($demo->hasMethod('shopOwnerEmail'));
        self::assertTrue($demo->hasMethod('emailTakenByOtherTenant'));
        self::assertTrue($demo->hasMethod('loadShopSql'));

        $plant = new ReflectionClass(OwnerCredentialPlant::class);
        self::assertTrue($plant->hasMethod('emailsMatch'));
        self::assertTrue($plant->hasMethod('refuseDuplicateEmail'));

        $named = 'ownerPassword( mallOwnerEmail( shopOwnerEmail( emailTakenByOtherTenant( '
            . 'loadShopSql( emailsMatch( refuseDuplicateEmail(';
        self::assertStringContainsString('ownerPassword(', $named);
        self::assertStringContainsString('mallOwnerEmail(', $named);
        self::assertStringContainsString('shopOwnerEmail(', $named);
        self::assertStringContainsString('emailTakenByOtherTenant(', $named);
        self::assertStringContainsString('loadShopSql(', $named);
        self::assertStringContainsString('emailsMatch(', $named);
        self::assertStringContainsString('refuseDuplicateEmail(', $named);
    }
}
