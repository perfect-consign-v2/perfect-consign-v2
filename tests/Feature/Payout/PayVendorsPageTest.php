<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;
use PHPUnit\Framework\TestCase;

final class PayVendorsPageTest extends TestCase
{
    protected function tearDown(): void
    {
        $needed = new \ReflectionProperty(ListTable::class, 'needed');
        $printed = new \ReflectionProperty(ListTable::class, 'printed');
        $needed->setValue(null, false);
        $printed->setValue(null, false);
    }

    public function testAnOpenMonthAsksHowToPay(): void
    {
        $html = $this->page('', '', '');

        self::assertStringContainsString('Pay these vendors', $html);
        self::assertStringContainsString('Pay from', $html);
        self::assertStringContainsString('Cash from the drawer', $html);
        self::assertStringContainsString('Cash from the safe', $html);
        self::assertStringContainsString('First check number', $html);
        self::assertStringContainsString('Print this list', $html);
        self::assertStringNotContainsString('Print checks', $html);
        self::assertStringNotContainsString('Close October', $html);
        self::assertStringContainsString('1 to pay', $html);
    }

    public function testAPaidCheckMonthReprintsTheSavedPaper(): void
    {
        $html = $this->page('00000000-0000-7000-8000-000000000020', 'check', 'paid');

        self::assertStringContainsString('October is paid.', $html);
        self::assertStringContainsString('/payouts/report?run=00000000-0000-7000-8000-000000000020', $html);
        self::assertStringContainsString('/payouts/checks?run=00000000-0000-7000-8000-000000000020', $html);
        self::assertStringContainsString('Print each vendor\'s sales', $html);
        self::assertStringContainsString('Print checks', $html);
        self::assertStringNotContainsString('Pay these vendors', $html);
        self::assertStringContainsString('1 paid', $html);
    }

    public function testAPaidCashMonthDoesNotOfferChecks(): void
    {
        $html = $this->page('00000000-0000-7000-8000-000000000021', 'safe_cash', 'paid');

        self::assertStringContainsString('October is paid.', $html);
        self::assertStringContainsString('Print each vendor\'s sales', $html);
        self::assertStringNotContainsString('Print checks', $html);
        self::assertStringNotContainsString('Pay these vendors', $html);
    }

    private function page(string $runId, string $tender, string $status): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Payout/pay-vendors', [
            'slug' => 'webprobe',
            'csrf' => 'token',
            'displayName' => 'Web Probe',
            'monthName' => 'October',
            'error' => null,
            'excluded' => [],
            'savedRun' => $runId,
            'savedTender' => $tender,
            'savedStatus' => $status,
            'pack' => [[
                'vendor_id' => 'vendor-1',
                'code' => 'ADA',
                'name' => 'Ada Lane',
                'booth' => 'A-1',
                'prior' => 0,
                'charged' => 30000,
                'paid' => 0,
                'payable' => 10200,
                'rent' => 3000,
                'check' => 7200,
                'owes' => 0,
            ]],
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
