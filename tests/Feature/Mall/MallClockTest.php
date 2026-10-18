<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Mall;

use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Mall\MallRentDraft;
use PerfectConsign\Feature\Mall\MallSettings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MallClockTest extends TestCase
{
    public function testAnUnparseableValueIsKeptAsText(): void
    {
        $clock = (new ReflectionClass(MallClock::class))->newInstanceWithoutConstructor();

        self::assertSame('not-a-time', $clock->localTime('not-a-time'));
        self::assertSame('', $clock->localTime(12));
        self::assertSame('', $clock->localDate('not-a-time'));
    }

    public function testAnUnknownCatalogFlagIsRefused(): void
    {
        $mall = (new ReflectionClass(MallSettings::class))->newInstanceWithoutConstructor();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown catalog flag');
        $mall->enabled('use_glitter');
    }

    public function testRentDraftDollarsHideAZeroAmount(): void
    {
        $draft = (new ReflectionClass(MallRentDraft::class))->newInstanceWithoutConstructor();

        self::assertSame('', $draft->dollars(0));
        self::assertSame('1.05', $draft->dollars(105));
    }
}
