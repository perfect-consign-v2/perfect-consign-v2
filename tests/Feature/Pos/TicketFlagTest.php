<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Pos\TicketFlag;
use PHPUnit\Framework\TestCase;

final class TicketFlagTest extends TestCase
{
    public function testKnownTrueValuesTurnTheFlagOn(): void
    {
        self::assertTrue(TicketFlag::on(true));
        self::assertTrue(TicketFlag::on(1));
        self::assertTrue(TicketFlag::on('1'));
        self::assertTrue(TicketFlag::on('t'));
        self::assertTrue(TicketFlag::on('true'));
        self::assertFalse(TicketFlag::on(false));
        self::assertFalse(TicketFlag::on('0'));
        self::assertFalse(TicketFlag::on(null));
    }
}
