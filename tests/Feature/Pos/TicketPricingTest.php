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

    public function testTheDatabaseQuoteIsShownAsItIs(): void
    {
        $asked = [];
        $statements = $this->createStub(Statements::class);
        $statements->method('change');
        $statements->method('bound')->willReturnCallback(static function (string $sql, array $values) use (&$asked): array {
            $asked = [$sql, $values];

            return [[
                'line_no' => 1,
                'item_id' => 'item-1',
                'price_minor' => 1000,
                'quantity' => 2,
                'commission_bps' => 1000,
                'discount_minor' => 175,
                'store_discount_minor' => 10,
                'commission_minor' => 183,
                'tax_minor' => 145,
                'tax_exempt' => 'false',
                'line_discount_minor' => 100,
                'offer_discount_minor' => 25,
                'offer_name' => 'Two for one',
                'line_discount_kind' => 'amount',
                'line_discount_value' => 1,
                'code' => 'c1',
                'description' => 'Coat',
                'vendor_id' => 'v1',
                'placement' => 'consigned',
                'cost_minor' => null,
                'vendor_name' => 'Ada',
            ]];
        });

        self::assertSame([[
            'line_no' => 1,
            'item_id' => 'item-1',
            'code' => 'c1',
            'description' => 'Coat',
            'vendor' => 'Ada',
            'vendor_id' => 'v1',
            'placement' => 'consigned',
            'cost' => 0,
            'price' => 1000,
            'quantity' => 2,
            'commission' => 183,
            'commission_bps' => 1000,
            'tax' => 145,
            'tax_exempt' => false,
            'discount' => 175,
            'store_discount' => 10,
            'offer_discount' => 25,
            'offer_name' => 'Two for one',
            'line_discount' => 100,
            'line_discount_kind' => 'amount',
            'line_discount_value' => '0.01',
        ]], (new TicketPricing($statements))->lines('ticket-1'));
        self::assertStringContainsString('FROM stall_ticket_quote(?::uuid) AS quote', $asked[0]);
        self::assertSame(['ticket-1', 'ticket-1'], $asked[1]);
    }

    public function testATaxExemptPieceAndAPercentAreShown(): void
    {
        $line = $this->quoted('true', 'percent', 850)[0];
        self::assertTrue($line['tax_exempt']);
        self::assertSame('8.50', $line['line_discount_value']);

        $shown = new ReflectionMethod(TicketPricing::class, 'shown');
        self::assertSame('', $shown->invoke(null, 'percent', 0));
        self::assertSame('8', $shown->invoke(null, 'percent', 800));
        self::assertSame('1.05', $shown->invoke(null, 'amount', 105));
    }

    public function testAQuoteWithoutItsTaxFlagIsRefused(): void
    {
        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('the register quote is missing');
        $this->quoted('t', '', null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quoted(string $exempt, string $kind, ?int $value): array
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('change');
        $statements->method('bound')->willReturn([[
            'line_no' => 1,
            'item_id' => 'item-1',
            'price_minor' => 1000,
            'quantity' => 1,
            'commission_bps' => 0,
            'discount_minor' => 0,
            'store_discount_minor' => 0,
            'commission_minor' => 0,
            'tax_minor' => 0,
            'tax_exempt' => $exempt,
            'line_discount_minor' => 0,
            'offer_discount_minor' => 0,
            'offer_name' => '',
            'line_discount_kind' => $kind,
            'line_discount_value' => $value,
            'code' => 'c1',
            'description' => 'Coat',
            'vendor_id' => 'v1',
            'placement' => 'consigned',
            'cost_minor' => 500,
            'vendor_name' => 'Ada',
        ]]);

        return (new TicketPricing($statements))->lines('ticket-1');
    }
}
