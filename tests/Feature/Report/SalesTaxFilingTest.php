<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Report;

use PerfectConsign\Feature\Report\SalesTaxFiling;
use PHPUnit\Framework\TestCase;

final class SalesTaxFilingTest extends TestCase
{
    public function testTaxableExemptAndReturnsAddUp(): void
    {
        $filing = (new SalesTaxFiling())->filing([
            ['section' => 'taxable', 'label' => 'Taxed', 'subtotal_minor' => 1000, 'discount_minor' => 100, 'tax_minor' => 80],
            ['section' => 'exempt', 'label' => 'Free', 'subtotal_minor' => 500, 'discount_minor' => 50, 'tax_minor' => 0],
            ['section' => 'return', 'label' => 'Back', 'subtotal_minor' => -200, 'discount_minor' => 20, 'tax_minor' => -16],
        ]);

        self::assertSame(1500, $filing['gross']);
        self::assertSame(450, $filing['exempt']);
        self::assertSame(220, $filing['returns']);
        self::assertSame(680, $filing['taxable']);
        self::assertSame(64, $filing['collected']);
    }
}
