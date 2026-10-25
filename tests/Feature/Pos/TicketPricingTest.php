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

    public function testAStoredAdjustmentIsReadBack(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([[
            'discount_kind' => 'percent',
            'discount_value' => 500,
            'discount_reason' => 'sale',
            'tax_waiver_reason' => '',
        ]]);

        self::assertSame([
            'kind' => 'percent',
            'value' => 500,
            'reason' => 'sale',
            'waiver' => '',
        ], (new TicketPricing($statements))->adjustment('ticket-1'));
    }

    public function testABlankDiscountKindIsNotAKind(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([[
            'discount_kind' => '',
            'discount_value' => 500,
            'discount_reason' => '',
            'tax_waiver_reason' => '',
        ]]);

        self::assertNull((new TicketPricing($statements))->adjustment('ticket-1')['kind']);
    }

    public function testAMissingKindOrAZeroValueClearsTheDiscount(): void
    {
        $clears = 0;
        $statements = $this->createStub(Statements::class);
        $statements->method('change')->willReturnCallback(static function () use (&$clears): void {
            $clears++;
        });
        $pricing = new TicketPricing($statements);
        $pricing->discount('ticket-1', null, 500, 'sale');
        $pricing->discount('ticket-1', 'percent', 0, 'sale');

        self::assertSame(2, $clears);
    }

    public function testQuantityAndEachDiscountChangeTheQuote(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('change');
        $statements->method('bound')->willReturnOnConsecutiveCalls(
            [[
                'line_no' => 1,
                'item_id' => 'item-1',
                'price_minor' => 1000,
                'quantity' => 2,
                'line_discount_minor' => 100,
                'offer_discount_minor' => 25,
                'store_discount_minor' => 10,
                'offer_name' => '',
                'code' => 'c1',
                'description' => 'Coat',
                'vendor_id' => 'v1',
                'vendor_name' => 'Ada',
                'tax_exempt' => false,
                'line_discount_kind' => 'amount',
                'line_discount_value' => 1,
                'commission_bps' => 1000,
            ]],
            [['share' => 50]],
        );
        $lines = (new TicketPricing($statements))->lines('ticket-1', 8000, [
            'kind' => 'amount',
            'value' => 50,
            'reason' => 'sale',
            'waiver' => '',
        ]);

        self::assertSame(183, $lines[0]['commission']);
        self::assertSame(145, $lines[0]['tax']);
        self::assertSame(175, $lines[0]['discount']);
        self::assertSame('0.01', $lines[0]['line_discount_value']);
    }

    public function testAMallSaleTakenByTheVendorChangesTheDiscount(): void
    {
        $vendor = $this->quotedMall('vendor');
        self::assertSame(200, $vendor[0]['discount']);
        self::assertSame(0, $vendor[0]['store_discount']);

        $mall = $this->quotedMall('mall');
        self::assertSame(0, $mall[0]['discount']);
        self::assertSame(200, $mall[0]['store_discount']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quotedMall(string $absorbed): array
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('change');
        $statements->method('rows')->willReturn([['percent_bps' => 1000, 'absorbed_by' => $absorbed]]);
        $statements->method('bound')->willReturnOnConsecutiveCalls(
            [[
                'line_no' => 1,
                'item_id' => 'item-1',
                'price_minor' => 1000,
                'quantity' => 2,
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
                'line_discount_value' => 0,
                'commission_bps' => 0,
            ]],
            [['share' => 0]],
        );

        return (new TicketPricing($statements))->lines('ticket-1', 0, [
            'kind' => null,
            'value' => null,
            'reason' => '',
            'waiver' => '',
        ]);
    }
}
