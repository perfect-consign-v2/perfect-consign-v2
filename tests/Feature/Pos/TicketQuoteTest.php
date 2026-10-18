<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Pos\TicketQuote;
use PHPUnit\Framework\TestCase;

final class TicketQuoteTest extends TestCase
{
    public function testTaxComesOffTheDiscountedPrice(): void
    {
        self::assertSame(80, TicketQuote::tax(1000, 0, 8000));
        self::assertSame(64, TicketQuote::tax(1000, 200, 8000));
        self::assertSame('8%', TicketQuote::percentLabel(800));
        self::assertSame('8.50%', TicketQuote::percentLabel(850));
        self::assertSame('8.05%', TicketQuote::percentLabel(805));
    }
}
