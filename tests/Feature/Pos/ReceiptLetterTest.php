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
        self::assertStringContainsString('Lamp  $10.00', $body);
        self::assertStringContainsString('Tax  $0.80', $body);
        self::assertStringContainsString('Total  $10.80', $body);
        self::assertStringContainsString('Thank you', $body);
    }
}
