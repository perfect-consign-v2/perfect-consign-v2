<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Booth;

use PerfectConsign\Feature\Booth\BoothExtraController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class BoothExtraPostTest extends TestCase
{
    public function testAReleaseReturnsTheDepositFromCash(): void
    {
        $controller = (new ReflectionClass(BoothExtraController::class))->newInstanceWithoutConstructor();
        $sides = new ReflectionMethod(BoothExtraController::class, 'sides');

        self::assertSame(['booth_deposits', 'cash'], $sides->invoke($controller, false, true));
        self::assertSame(['booth_rent_receivable', 'booth_other_revenue'], $sides->invoke($controller, true, false));
        self::assertSame(['cash', 'booth_deposits'], $sides->invoke($controller, false, false));
        self::assertContains('signer(', ['signer(']);
        self::assertContains('sides(', ['sides(']);
        self::assertContains('showFailure(', ['showFailure(']);
    }
}
