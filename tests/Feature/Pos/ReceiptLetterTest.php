<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Pos\ReceiptLetter;
use PHPUnit\Framework\TestCase;

final class ReceiptLetterTest extends TestCase
{
    public function testTheLetterListsTheSale(): void
    {
        $body = (new ReceiptLetter())->body([
            'sale_number' => 12,
            'posted_on' => '2026-10-20',
            'voided' => false,
            'lines' => [[
                'description' => 'Lamp',
                'code' => 'L1',
                'price' => 1000,
                'quantity' => 1,
                'discount' => 0,
                'tax' => 80,
            ]],
            'tenders' => [['name' => 'Cash', 'amount' => 1080]],
            'round' => 0,
            'discount' => 0,
        ], 'Mall', ['Mall', '1 Main'], 'Thank you');

        self::assertStringContainsString('Sale 12', $body);
        self::assertStringContainsString('1 Main', $body);
        self::assertSame(1, substr_count($body, 'Mall'));
        self::assertStringContainsString('Lamp  $10.00', $body);
        self::assertStringNotContainsString('Voided', $body);
        self::assertStringNotContainsString('Discount', $body);
        self::assertStringNotContainsString('Cash round', $body);
        self::assertStringContainsString('Tax  $0.80', $body);
        self::assertStringContainsString('Total  $10.80', $body);
        self::assertStringContainsString('Thank you', $body);
    }

    public function testAZeroQuantityStillBillsOneAndTheAdjustmentsStaySigned(): void
    {
        $one = (new ReceiptLetter())->body([
            'sale_number' => 3,
            'posted_on' => '2026-10-20',
            'voided' => false,
            'lines' => [[
                'description' => 'Lamp',
                'code' => 'L1',
                'price' => 1000,
                'quantity' => 0,
                'discount' => 0,
                'tax' => 0,
            ]],
            'tenders' => [],
            'round' => 0,
            'discount' => 0,
        ], 'Mall', ['1 Main'], '');

        self::assertStringContainsString('Lamp  $10.00', $one);
        self::assertStringContainsString("1  L1", $one);

        $body = (new ReceiptLetter())->body([
            'sale_number' => 4,
            'posted_on' => '2026-10-20',
            'voided' => true,
            'lines' => [[
                'description' => 'Lamp',
                'code' => 'L1',
                'price' => 1000,
                'quantity' => 2,
                'discount' => 100,
                'store_discount' => 40,
                'tax' => 80,
            ]],
            'tenders' => [],
            'round' => 5,
            'discount' => 250,
        ], 'Mall', [''], 'Thanks');

        self::assertStringContainsString("Mall\n", $body);
        self::assertStringContainsString('Voided', $body);
        self::assertStringContainsString('Lamp  $18.60', $body);
        self::assertStringContainsString("2  L1", $body);
        self::assertStringContainsString('Subtotal  $20.00', $body);
        self::assertStringContainsString('Discount  $2.50', $body);
        self::assertStringContainsString('Cash round  $0.05', $body);
        self::assertStringContainsString('Total  $18.35', $body);
    }
}
