<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Pos\TicketPricing;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TicketPricingTest extends TestCase
{
    public function testAMissingTicketHasAnEmptyAdjustment(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([]);
        $pricing = new TicketPricing($statements);

        self::assertSame(['kind' => null, 'value' => null, 'reason' => '', 'waiver' => ''], $pricing->adjustment('missing'));
    }

    public function testAZeroDiscountClearsTheTicket(): void
    {
        $cleared = false;
        $statements = $this->createStub(Statements::class);
        $statements->method('change')->willReturnCallback(static function () use (&$cleared): void {
            $cleared = true;
        });
        (new TicketPricing($statements))->discount('ticket-1', null, 0, null);
        self::assertTrue($cleared);
    }

    public function testADiscountOnAClosedTicketIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('affected')->willReturn(0);
        try {
            (new TicketPricing($statements))->discount('ticket-1', 'percent', 500, 'sale');
            self::fail('a closed ticket was discounted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('there is no open ticket', $error->getMessage());
        }
        try {
            (new TicketPricing($statements))->waive('ticket-1', 'tax-free');
            self::fail('a closed ticket waived tax');
        } catch (DatabaseRefusal $error) {
            self::assertSame('there is no open ticket', $error->getMessage());
        }
    }

    public function testQuotedLinesHonorTaxFlagsAndShownPercents(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('change');
        $statements->method('bound')->willReturnOnConsecutiveCalls(
            [[
                'line_no' => 1,
                'item_id' => 'item-1',
                'price_minor' => 1000,
                'quantity' => 1,
                'line_discount_minor' => 0,
                'offer_discount_minor' => 0,
                'store_discount_minor' => 0,
                'offer_name' => '',
                'code' => 'c1',
                'description' => 'Coat',
                'vendor_id' => 'v1',
                'vendor_name' => 'Ada',
                'tax_exempt' => 't',
                'line_discount_kind' => 'percent',
                'line_discount_value' => 850,
                'commission_bps' => 1000,
            ]],
            [['share' => 0]],
        );
        $lines = (new TicketPricing($statements))->lines('ticket-1', 8000, [
            'kind' => null,
            'value' => null,
            'reason' => '',
            'waiver' => '',
        ]);
        self::assertSame(0, $lines[0]['tax']);
        self::assertSame('8.50', $lines[0]['line_discount_value']);

        $shown = new ReflectionMethod(TicketPricing::class, 'shown');
        self::assertSame('', $shown->invoke(null, 'percent', 0));
        self::assertSame('8', $shown->invoke(null, 'percent', 800));
        self::assertSame('1.05', $shown->invoke(null, 'amount', 105));
        $flag = new ReflectionMethod(TicketPricing::class, 'flag');
        self::assertTrue($flag->invoke(null, true));
        self::assertTrue($flag->invoke(null, 1));
        self::assertTrue($flag->invoke(null, '1'));
        self::assertTrue($flag->invoke(null, 'true'));
        self::assertFalse($flag->invoke(null, 'f'));
    }

    public function testAWaiverZeroesTaxOnAQuotedLine(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('change');
        $statements->method('bound')->willReturnOnConsecutiveCalls(
            [[
                'line_no' => 1,
                'item_id' => 'item-1',
                'price_minor' => 1000,
                'quantity' => 1,
                'line_discount_minor' => 0,
                'offer_discount_minor' => 0,
                'store_discount_minor' => 0,
                'offer_name' => '',
                'code' => 'c1',
                'description' => 'Coat',
                'vendor_id' => 'v1',
                'vendor_name' => 'Ada',
                'tax_exempt' => false,
                'line_discount_kind' => '',
                'line_discount_value' => null,
                'commission_bps' => 0,
            ]],
            [['share' => 0]],
        );
        $lines = (new TicketPricing($statements))->lines('ticket-1', 8000, [
            'kind' => null,
            'value' => null,
            'reason' => '',
            'waiver' => 'non-profit',
        ]);
        self::assertSame(0, $lines[0]['tax']);
        self::assertSame('', $lines[0]['line_discount_value']);
    }
}
