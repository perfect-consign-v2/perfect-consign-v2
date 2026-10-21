<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Book;

use InvalidArgumentException;
use PerfectConsign\Feature\Book\MissedSaleQuote;
use PHPUnit\Framework\TestCase;

final class MissedSaleQuoteTest extends TestCase
{
    public function testTheVendorKeepsTheSaleShare(): void
    {
        self::assertSame(6000, MissedSaleQuote::vendorShare(10000, 4000));
        self::assertSame(10000, MissedSaleQuote::vendorShare(10000, 0));
    }

    public function testAFullStoreTakeOwesNothing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MissedSaleQuote::vendorShare(10000, 10000);
        // record( vendors( rate( page( form( submit( openPayable(
    }
}
