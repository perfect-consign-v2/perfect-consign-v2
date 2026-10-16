<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class ChecksPageTest extends TestCase
{
    public function testACheckPrintsTheVendorTheBoothAndTheAmount(): void
    {
        $html = $this->checks([
            [
                'number' => '1042',
                'name' => 'Ada Lane',
                'code' => 'ADA',
                'booth' => 'A-1',
                'amount' => 31200,
                'words' => 'Three hundred twelve and 00/100',
            ],
            [
                'number' => '1043',
                'name' => 'Gina Holt',
                'code' => 'GIN',
                'booth' => '',
                'amount' => 5000,
                'words' => 'Fifty and 00/100',
            ],
        ]);

        self::assertStringContainsString('One check for each vendor who is getting paid.', $html);
        self::assertStringContainsString('Web Probe', $html);
        self::assertStringContainsString('Check 1042', $html);
        self::assertStringContainsString('10-12-2026', $html);
        self::assertStringContainsString('Pay to the order of Ada Lane', $html);
        self::assertStringContainsString('$312.00', $html);
        self::assertStringContainsString('Three hundred twelve and 00/100', $html);
        self::assertStringContainsString('Memo: October payout, booth A-1 (ADA)', $html);
        self::assertStringContainsString('Check 1043', $html);
        self::assertStringContainsString('Memo: October payout (GIN)', $html);
        self::assertStringContainsString('data-print', $html);
        self::assertStringContainsString('.check-sheet:last-of-type { break-after: auto; page-break-after: auto; margin-bottom: 0; }', $html);
        self::assertStringNotContainsString('.check-sheet:last-child', $html);
    }

    public function testTheFirstCheckNumberFormShowsAProblem(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Payout/check-number', [
            'slug' => 'mall',
            'csrf' => 'token',
            'displayName' => 'Web Probe',
            'runId' => 'run-1',
            'error' => 'Type the number on the first check.',
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);
        self::assertStringContainsString('Print checks', $html);
        self::assertStringContainsString('Type the number on the first check.', $html);
        self::assertStringContainsString('name="starting_check"', $html);
        self::assertStringContainsString('/mall/payouts/checks?run=run-1', $html);
    }

    public function testAQuietCheckNumberFormHidesTheAlert(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Payout/check-number', [
            'slug' => 'mall',
            'csrf' => 'token',
            'displayName' => 'Web Probe',
            'runId' => 'run-1',
            'error' => null,
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertStringNotContainsString('<p class="error">', $html);
        self::assertStringContainsString('name="starting_check"', $html);
    }

    /**
     * @param list<array{number: string, name: string, code: string, booth: string, amount: int, words: string}> $checks
     */
    private function checks(array $checks): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Payout/checks', [
            'displayName' => 'Web Probe',
            'dated' => '10-12-2026',
            'monthName' => 'October',
            'checks' => $checks,
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
