<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Vendor;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class PastCheckPageTest extends TestCase
{
    public function testAPastCheckListsWhatWasOnIt(): void
    {
        $html = $this->page('1042', [
            ['date' => '2026-09-30', 'label' => 'Sales', 'amount' => 61200, 'run_id' => 'run-1'],
            ['date' => '2026-10-01', 'label' => 'Rent', 'amount' => -30000, 'run_id' => 'run-1'],
        ]);

        self::assertStringContainsString('Check 10-01-2026', $html);
        self::assertStringContainsString('$312.00', $html);
        self::assertStringContainsString('No. 1042', $html);
        self::assertStringContainsString('href="/mall/vendor"', $html);
        self::assertStringContainsString('09-30-2026', $html);
        self::assertStringContainsString('Sales', $html);
        self::assertStringContainsString('$612.00', $html);
        self::assertStringContainsString('-$300.00', $html);
        self::assertStringNotContainsString('No lines on this check.', $html);
    }

    public function testACheckWithNoNumberAndNoLinesSaysSo(): void
    {
        $html = $this->page('', []);

        self::assertStringContainsString('Check 10-01-2026', $html);
        self::assertStringNotContainsString('No. ', $html);
        self::assertStringContainsString('No lines on this check.', $html);
    }

    /**
     * @param list<array{date: string, label: string, amount: int, run_id: string}> $lines
     */
    private function page(string $number, array $lines): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Vendor/past-check', [
            'slug' => 'mall',
            'check' => [
                'paid_on' => '2026-10-01',
                'amount' => 31200,
                'number' => $number,
                'lines' => $lines,
            ],
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
