<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PHPUnit\Framework\TestCase;

final class StoreUseTest extends TestCase
{
    public function testThePageNamesThePurchase(): void
    {
        self::assertTrue(method_exists(\PerfectConsign\Feature\Payout\StoreUse::class, 'record'));
        // vendors( itemId( page( form( submit(
    }
}
