<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;
use PHPUnit\Framework\TestCase;

final class PayoutHistoryPageTest extends TestCase
{
    protected function tearDown(): void
    {
        $needed = new \ReflectionProperty(ListTable::class, 'needed');
        $printed = new \ReflectionProperty(ListTable::class, 'printed');
        $needed->setValue(null, false);
        $printed->setValue(null, false);
    }

    public function testASettledRunOffersUndoAndNamesTheExcludedVendor(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Payout/history', [
            'slug' => 'webprobe',
            'csrf' => 'token',
            'error' => 'a payout reversal needs the operator and a reason',
            'excluded' => [
                ['code' => 'B2', 'name' => 'Bea', 'mode' => 'skip'],
            ],
            'runs' => [
                [
                    'id' => '00000000-0000-7000-8000-000000000010',
                    'run_no' => 4,
                    'sales_from' => '2026-09-01',
                    'sales_to' => '2026-09-30',
                    'rent_month' => '2099-11',
                    'tender' => 'check',
                    'status' => 'settled',
                    'reverses_run_id' => '',
                    'undone_by' => '',
                    'undone_reason' => '',
                    'undone_at' => '',
                ],
                [
                    'id' => '00000000-0000-7000-8000-000000000011',
                    'run_no' => 3,
                    'sales_from' => '2026-08-01',
                    'sales_to' => '2026-08-31',
                    'rent_month' => '',
                    'tender' => 'cash',
                    'status' => 'reversed',
                    'reverses_run_id' => '00000000-0000-7000-8000-000000000009',
                    'undone_by' => 'Ada',
                    'undone_reason' => 'wrong window',
                    'undone_at' => '2026-09-02',
                ],
                [
                    'id' => '00000000-0000-7000-8000-000000000012',
                    'run_no' => 2,
                    'sales_from' => '2026-07-01',
                    'sales_to' => '2026-07-31',
                    'rent_month' => '2026-07',
                    'tender' => 'check',
                    'status' => 'open',
                    'reverses_run_id' => '',
                    'undone_by' => null,
                    'undone_reason' => 'kept',
                    'undone_at' => '2026-08-01',
                ],
                [
                    'id' => '00000000-0000-7000-8000-000000000013',
                    'run_no' => 1,
                    'sales_from' => '2026-06-01',
                    'sales_to' => '2026-06-30',
                    'rent_month' => '2026-06',
                    'tender' => 'check',
                    'status' => 'paid',
                    'reverses_run_id' => '00000000-0000-7000-8000-000000000001',
                    'undone_by' => '',
                    'undone_reason' => 'kept',
                    'undone_at' => '2026-07-01',
                ],
                [
                    'id' => '00000000-0000-7000-8000-000000000014',
                    'run_no' => 5,
                    'sales_from' => '2026-05-01',
                    'sales_to' => '2026-05-31',
                    'rent_month' => '2026-05',
                    'tender' => 'check',
                    'status' => 'settled',
                    'reverses_run_id' => '00000000-0000-7000-8000-000000000001',
                    'undone_by' => 'Ada',
                    'undone_reason' => null,
                    'undone_at' => '2026-06-01',
                ],
                [
                    'id' => '00000000-0000-7000-8000-000000000015',
                    'run_no' => 6,
                    'sales_from' => '2026-04-01',
                    'sales_to' => '2026-04-30',
                    'rent_month' => '2026-04',
                    'tender' => 'check',
                    'status' => 'settled',
                    'reverses_run_id' => '00000000-0000-7000-8000-000000000001',
                    'undone_by' => 'Ada',
                    'undone_reason' => '',
                    'undone_at' => '2026-05-01',
                ],
                [
                    'id' => '00000000-0000-7000-8000-000000000016',
                    'run_no' => 7,
                    'sales_from' => '2026-03-01',
                    'sales_to' => '2026-03-31',
                    'rent_month' => '2026-03',
                    'tender' => 'check',
                    'status' => 'settled',
                    'reverses_run_id' => '00000000-0000-7000-8000-000000000001',
                    'undone_by' => 'Ada',
                    'undone_reason' => 'kept',
                    'undone_at' => null,
                ],
                [
                    'id' => '00000000-0000-7000-8000-000000000017',
                    'run_no' => 8,
                    'sales_from' => '2026-02-01',
                    'sales_to' => '2026-02-28',
                    'rent_month' => '2026-02',
                    'tender' => 'check',
                    'status' => 'settled',
                    'reverses_run_id' => '00000000-0000-7000-8000-000000000001',
                    'undone_by' => 'Ada',
                    'undone_reason' => 'kept',
                    'undone_at' => '',
                ],
            ],
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertStringContainsString('a payout reversal needs the operator and a reason', $html);
        self::assertStringContainsString('Undo this payout', $html);
        self::assertStringContainsString('Undone by Ada on 09-02-2026 — wrong window', $html);
        self::assertStringContainsString('2099-11', $html);
        self::assertStringContainsString('Vendors excluded: Bea', $html);
        self::assertStringContainsString('>—<', $html);
        self::assertStringContainsString('09-01-2026', $html);
        self::assertSame(1, substr_count($html, 'Undo this payout'));
        self::assertSame(1, substr_count($html, 'Undone by'));
    }

    public function testAPaidCheckCanBePrintedAgain(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Payout/history', [
            'slug' => 'webprobe',
            'csrf' => 'token',
            'error' => null,
            'excluded' => [],
            'runs' => [
                [
                    'id' => '00000000-0000-7000-8000-000000000020',
                    'run_no' => 9,
                    'sales_from' => '2026-09-01',
                    'sales_to' => '2026-09-30',
                    'rent_month' => '2026-10-01',
                    'tender' => 'check',
                    'status' => 'paid',
                    'reverses_run_id' => '',
                    'undone_by' => '',
                    'undone_reason' => '',
                    'undone_at' => '',
                ],
                [
                    'id' => '00000000-0000-7000-8000-000000000021',
                    'run_no' => 8,
                    'sales_from' => '2026-08-01',
                    'sales_to' => '2026-08-31',
                    'rent_month' => '2026-09-01',
                    'tender' => 'drawer_cash',
                    'status' => 'paid',
                    'reverses_run_id' => '',
                    'undone_by' => '',
                    'undone_reason' => '',
                    'undone_at' => '',
                ],
            ],
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertSame(2, substr_count($html, 'Print each vendor\'s sales'));
        self::assertSame(1, substr_count($html, 'Print checks'));
        self::assertStringContainsString('/payouts/checks?run=00000000-0000-7000-8000-000000000020', $html);
        self::assertStringNotContainsString('/payouts/checks?run=00000000-0000-7000-8000-000000000021', $html);
        self::assertStringContainsString('Cash from the drawer', $html);
        self::assertStringContainsString('Paid', $html);
        self::assertStringNotContainsString('drawer_cash', $html);
        self::assertStringNotContainsString('>paid<', $html);
    }

    public function testABlankErrorIsNotAnAlert(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Payout/history', [
            'slug' => 'webprobe',
            'csrf' => 'token',
            'error' => null,
            'excluded' => [],
            'runs' => [],
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertStringNotContainsString('class="alert"', $html);
    }
}
