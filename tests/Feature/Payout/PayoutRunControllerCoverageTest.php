<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Payout\PayoutRunController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class PayoutRunControllerCoverageTest extends TestCase
{
    public function testPastPayoutsShowAnUndoneTimeWhenOneExists(): void
    {
        $runs = (new ReflectionClass(PayoutRunController::class))->newInstanceWithoutConstructor();
        $clock = (new ReflectionClass(MallClock::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(PayoutRunController::class, 'clock'))->setValue($runs, $clock);
        $shown = (new ReflectionMethod(PayoutRunController::class, 'shown'))->invoke($runs, [
            ['id' => '1', 'reversed_at' => 'not-a-time'],
            ['id' => '2', 'reversed_at' => ''],
            ['id' => '3', 'reversed_at' => null],
        ]);
        self::assertSame('not-a-time', $shown[0]['undone_at'] ?? null);
        self::assertSame('', $shown[1]['undone_at'] ?? null);
        self::assertSame('', $shown[2]['undone_at'] ?? null);
        self::assertSame('', (new ReflectionMethod(PayoutRunController::class, 'drawer'))->invoke(
            $runs,
            'webprobe',
            'check'
        ));
        self::assertSame('', (new ReflectionMethod(PayoutRunController::class, 'drawer'))->invoke(
            $runs,
            'webprobe',
            'safe_cash'
        ));
    }
}
