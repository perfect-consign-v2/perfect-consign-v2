<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class ChecksPageTest extends TestCase
{
    public function testACheckPrintsThePayeeTheAmountAndTheSalesSheet(): void
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

        self::assertStringContainsString('The check stock already has the company name and the check number.', $html);
        self::assertStringContainsString('href="/mall/payouts/report?run=run-1"', $html);
        self::assertStringContainsString('Print sales and rent', $html);
        self::assertStringContainsString('10-12-2026', $html);
        self::assertStringContainsString('Pay to the order of Ada Lane', $html);
        self::assertStringContainsString('$312.00', $html);
        self::assertStringContainsString('Three hundred twelve and 00/100', $html);
        self::assertStringContainsString('Memo: October payout, booth A-1 (ADA)', $html);
        self::assertStringContainsString('Memo: October payout (GIN)', $html);
        self::assertStringContainsString('data-print', $html);
        self::assertStringContainsString('/assets/checks-print.css', $html);
        self::assertStringContainsString('/assets/js/print-trigger.js', $html);
        self::assertStringNotContainsString('Web Probe', $html);
        self::assertStringNotContainsString('Check 1042', $html);
        self::assertStringNotContainsString('Check 1043', $html);
        self::assertStringNotContainsString('name="starting_check"', $html);
        self::assertStringNotContainsString('<style>', $html);
    }

    /**
     * @param list<array{number: string, name: string, code: string, booth: string, amount: int, words: string}> $checks
     */
    private function checks(array $checks): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Payout/checks', [
            'slug' => 'mall',
            'runId' => 'run-1',
            'dated' => '10-12-2026',
            'monthName' => 'October',
            'checks' => $checks,
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
