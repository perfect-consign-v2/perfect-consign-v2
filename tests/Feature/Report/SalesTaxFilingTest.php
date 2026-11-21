<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Report;

use PerfectConsign\Feature\Report\BooksReport;
use PerfectConsign\Feature\Report\ReportMonth;
use PerfectConsign\Feature\Report\SalesTaxFiling;
use PerfectConsign\Feature\Report\SalesTaxSheet;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class SalesTaxFilingTest extends TestCase
{
    public function testFilingAddsTaxableExemptAndReturns(): void
    {
        $filing = (new SalesTaxFiling())->filing([
            ['section' => 'taxable', 'label' => 'Taxed', 'subtotal_minor' => 1000, 'discount_minor' => 100, 'tax_minor' => 72],
            ['section' => 'exempt', 'label' => 'Exempt', 'subtotal_minor' => 500, 'discount_minor' => 0, 'tax_minor' => 0],
            ['section' => 'return', 'label' => 'Return', 'subtotal_minor' => -200, 'discount_minor' => 0, 'tax_minor' => -16],
            ['section' => 'void', 'label' => 'Void', 'subtotal_minor' => -50, 'discount_minor' => 0, 'tax_minor' => -4],
            ['section' => 'void_exempt', 'label' => 'Exempt voids', 'subtotal_minor' => -40, 'discount_minor' => 0, 'tax_minor' => 0],
            ['section' => 'liability', 'label' => 'Collected', 'subtotal_minor' => 0, 'discount_minor' => 0, 'tax_minor' => 99],
            ['section' => 'other', 'label' => 'Skip', 'subtotal_minor' => 9, 'discount_minor' => 0, 'tax_minor' => 1],
        ]);

        self::assertSame(1500, $filing['gross']);
        self::assertSame(460, $filing['exempt']);
        self::assertSame(290, $filing['returns']);
        self::assertSame(650, $filing['taxable']);
        self::assertSame(52, $filing['collected']);
    }

    public function testARateIsAPercentAndLiabilityIgnoresTheTaxColumn(): void
    {
        $filing = new SalesTaxFiling();
        $rates = $filing->rates([
            ['section' => 'rate', 'label' => '8000', 'subtotal_minor' => 10000],
            ['section' => 'taxable', 'label' => 'Taxed', 'subtotal_minor' => 1],
        ]);
        self::assertSame([['label' => '8.00%', 'base_minor' => 10000]], $rates);

        $owed = $filing->liability([
            ['section' => 'liability', 'label' => 'Opening', 'total_minor' => 100],
            ['section' => 'liability', 'label' => 'Collected', 'total_minor' => 80],
            ['section' => 'liability', 'label' => 'Paid', 'total_minor' => 30],
            ['section' => 'liability', 'label' => 'Closing', 'total_minor' => 150],
            ['section' => 'taxable', 'label' => 'Taxed', 'total_minor' => 9],
        ]);
        self::assertSame(150, $owed['closing']);
        self::assertSame(100, $owed['opening']);
    }

    public function testAMissingLiabilityLineIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That liability line is not on this page.');
        (new SalesTaxFiling())->liability([
            ['section' => 'liability', 'label' => 'Opening', 'total_minor' => 0],
        ]);
    }

    public function testTheSheetMapsEachColumn(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([[
            'section' => 'taxable',
            'label' => 'Taxed',
            'sale_count' => 2,
            'items_sold' => 3,
            'subtotal_minor' => 1000,
            'discount_minor' => 0,
            'tax_minor' => 80,
            'total_minor' => 1080,
        ]]);

        $rows = (new SalesTaxSheet($statements))->sheet('2026-11-01', '2026-11-30');

        self::assertSame('taxable', $rows[0]['section']);
        self::assertSame(1080, $rows[0]['total_minor']);
    }

    public function testBookTotalsSumTheRowsAndRefuseAMissingTotal(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'account_balances ORDER BY code')) {
                return [
                    ['code' => '1000', 'account_type' => 'asset', 'balance_minor' => 40],
                    ['code' => '2000', 'account_type' => 'liability', 'balance_minor' => -15],
                ];
            }
            if (str_contains($sql, 'journal_lines')) {
                return [['total' => 25]];
            }
            if (str_contains($sql, 'COALESCE(sum(balance_minor)')) {
                return [null];
            }

            return [];
        });
        $books = new BooksReport($statements);

        self::assertSame(25, $books->displayedTotal());
        self::assertSame('1000', $books->rows()[0]['code']);
        self::assertSame(25, $books->lineTotal());
        self::assertSame(0, $books->viewTotal());
    }

    public function testAMonthBecomesTheFirstAndLastDay(): void
    {
        $months = new ReportMonth();
        self::assertSame(['from' => '2026-11-01', 'to' => '2026-11-30'], $months->bounds('2026-11'));
        self::assertSame(['from' => '2024-02-01', 'to' => '2024-02-29'], $months->bounds('2024-02'));
        foreach (['', '2026-13', 'November'] as $month) {
            try {
                $months->bounds($month);
                self::fail($month);
            } catch (\InvalidArgumentException $error) {
                self::assertSame('a month is YYYY-MM', $error->getMessage());
            }
        }
    }
}
