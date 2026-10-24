<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Report;

use PerfectConsign\Feature\Report\BooksReport;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class BooksReportTest extends TestCase
{
    public function testTheDisplayedTotalIsTheSumOfTheRows(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturn([
            ['code' => '1000', 'account_type' => 'asset', 'balance_minor' => 100],
            ['code' => '2000', 'account_type' => 'liability', 'balance_minor' => 40],
        ]);

        self::assertSame(140, (new BooksReport($statements))->displayedTotal());
    }
}
