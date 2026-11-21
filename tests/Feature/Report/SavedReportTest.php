<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Report;

use PerfectConsign\Feature\Report\ReportCatalog;
use PerfectConsign\Feature\Report\SavedReport;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class SavedReportTest extends TestCase
{
    public function testASavedReportIsListedFoundAndStored(): void
    {
        $row = [
            'id' => '00000000-0000-7000-8000-000000000001',
            'name' => 'Saturday',
            'sheet' => 'sales',
            'grouped' => 'day',
            'range_key' => 'this_month',
        ];
        $seen = [];
        $statements = $this->createMock(Statements::class);
        $statements->method('rows')->willReturn([$row]);
        $statements->method('bound')->willReturnOnConsecutiveCalls([$row], []);
        $statements->expects(self::exactly(2))->method('change')->willReturnCallback(
            static function (string $sql, array $params) use (&$seen): void {
                $seen[] = [$sql, $params];
            }
        );
        $saved = new SavedReport($statements, new ReportCatalog());

        self::assertSame('Saturday', $saved->list()[0]['name']);
        $found = $saved->find('00000000-0000-7000-8000-000000000001');
        self::assertNotNull($found);
        self::assertSame('day', $found['grouped']);
        self::assertSame('/reports/sales?group=day&range=this_month', $saved->path($found));
        self::assertSame('/reports/rent', $saved->path([
            'sheet' => 'rent',
            'grouped' => 'none',
            'range_key' => 'now',
        ]));
        self::assertNull($saved->find('00000000-0000-7000-8000-000000000002'));
        $saved->save(' Saturday ', 'sales', 'day', 'this_month');
        $saved->remove('00000000-0000-7000-8000-000000000001');

        self::assertStringContainsString('INSERT INTO saved_reports', $seen[0][0]);
        self::assertSame(['Saturday', 'sales', 'day', 'this_month'], $seen[0][1]);
        self::assertStringContainsString('DELETE FROM saved_reports', $seen[1][0]);
    }
}

