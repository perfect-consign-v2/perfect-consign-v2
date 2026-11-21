<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Report;

use PerfectConsign\Feature\Report\ReportColumn;
use PerfectConsign\Feature\Report\ReportRows;
use PerfectConsign\Feature\Report\ReportTable;
use PHPUnit\Framework\TestCase;

final class ReportTableTest extends TestCase
{
    public function testACheckAndTwelveDollarsUseWordsOnTheScreenAndANumberInTheFile(): void
    {
        $grid = (new ReportRows())->payouts([[
            'paid_on' => '2026-06-15',
            'vendor' => 'Ada',
            'amount_minor' => 1200,
            'source_type' => 'check_disbursement',
        ]]);
        $table = new ReportTable();
        $lines = $table->cells($grid->columns, $grid->rows);
        $labels = array_map(static fn (ReportColumn $column): string => $column->label, $grid->columns);

        self::assertSame(['Date', 'Vendor', 'Amount', 'How paid'], $labels);
        self::assertSame('$12.00', $lines[0][2]);
        self::assertSame('Check', $lines[0][3]);
        $file = $table->document($grid->columns, $grid->rows);
        self::assertStringContainsString('12.00', $file);
        self::assertStringContainsString('Check', $file);
        self::assertStringNotContainsString('$12.00', $file);
    }
}
