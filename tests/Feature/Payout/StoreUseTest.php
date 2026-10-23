<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use InvalidArgumentException;
use PerfectConsign\Feature\Payout\StoreUse;
use PHPUnit\Framework\TestCase;

final class StoreUseTest extends TestCase
{
    public function testTheSameFormPostsOnce(): void
    {
        $token = '0123456789abcdef0123456789abcdef';
        $first = StoreUse::postingKey('01900000-0000-7000-8000-000000000e01', 'Ada-1', 1250, $token);
        $again = StoreUse::postingKey('01900000-0000-7000-8000-000000000e01', 'ada-1', 1250, $token);

        self::assertSame($first, $again);
        self::assertNotSame(
            $first,
            StoreUse::postingKey('01900000-0000-7000-8000-000000000e01', 'Ada-1', 1251, $token)
        );
        self::assertNotSame(
            $first,
            StoreUse::postingKey('01900000-0000-7000-8000-000000000e01', 'Ada-1', 1250, 'fedcba9876543210fedcba9876543210')
        );
    }

    public function testAMissingTokenIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StoreUse::postingKey('01900000-0000-7000-8000-000000000e01', 'Ada-1', 1250, '');
    }
}
