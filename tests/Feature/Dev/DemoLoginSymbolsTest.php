<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Dev;

use PerfectConsign\Feature\Dev\DemoLoginEnsure;
use PerfectConsign\Feature\Dev\OwnerCredentialPlant;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DemoLoginSymbolsTest extends TestCase
{
    public function testDemoLoginAndOwnerPlantSymbolsExist(): void
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
    }
}
