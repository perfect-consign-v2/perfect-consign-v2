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
    }
}
