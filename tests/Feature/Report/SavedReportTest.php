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
            'source' => 'sales',
            'grouped' => 'day',
        ];
        $statements = $this->createMock(Statements::class);
        $statements->method('rows')->willReturn([$row]);
        $statements->method('bound')->willReturnOnConsecutiveCalls([$row], []);
        $statements->expects(self::once())->method('change')->with(
            self::callback(static fn (string $sql): bool => str_contains($sql, 'INSERT INTO saved_reports')),
            ['Saturday', 'sales', 'day'],
        );
        $saved = new SavedReport($statements, new ReportCatalog());

        self::assertSame('Saturday', $saved->list()[0]['name']);
        $found = $saved->find('00000000-0000-7000-8000-000000000001');
        self::assertNotNull($found);
        self::assertSame('day', $found['grouped']);
        self::assertNull($saved->find('00000000-0000-7000-8000-000000000002'));
        $saved->save(' Saturday ', 'sales', 'day');
    }
}
