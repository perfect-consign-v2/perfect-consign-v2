<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class EnvelopePageTest extends TestCase
{
    public function testAStoredReportPrintsEachVendorOnce(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Payout/envelope', [
            'displayName' => 'Web Probe',
            'rows' => [
                $this->line('vendor-1', 'Ada', 'A1', 'BC-1', 'Wool coat', 1000, 200, 80, 720, '1042', 0),
                $this->line('vendor-1', 'Ada', 'A1', '', '', 0, 0, 0, 720, '1042', 0),
                $this->line('vendor-2', 'Bea', 'B2', 'BC-2', 'Lamp', 500, 100, 40, 360, '', 150),
                $this->line('vendor-3', 'Cy', 'C3', 'BC-3', 'Mug', 200, 40, 16, 0, '', 0),
            ],
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertSame(3, substr_count($html, 'class="envelope"'));
        self::assertStringContainsString('Wool coat', $html);
        self::assertStringContainsString('Check number 1042', $html);
        self::assertSame(2, substr_count($html, 'Check:'));
        self::assertSame(1, substr_count($html, 'Amount owed to store'));
        self::assertStringNotContainsString('Check number </p>', $html);
        self::assertStringContainsString('Booth 12', $html);
        self::assertStringContainsString('what they sold and the check', $html);
        self::assertStringNotContainsString("vendor's envelope", $html);
        self::assertStringContainsString('>Print<', $html);
        self::assertStringContainsString('window.print()', $html);
    }

    /**
     * @return array{vendor_id: string, code: string, name: string, booth: string, sold_on: string, item_code: string, description: string, price: int, commission: int, tax: int, check: int, owes: int, check_number: string}
     */
    private function line(
        string $vendorId,
        string $name,
        string $code,
        string $itemCode,
        string $description,
        int $price,
        int $commission,
        int $tax,
        int $check,
        string $checkNumber,
        int $owes,
    ): array {
        return [
            'vendor_id' => $vendorId,
            'code' => $code,
            'name' => $name,
            'booth' => '12',
            'sold_on' => '2026-10-01',
            'item_code' => $itemCode,
            'description' => $description,
            'price' => $price,
            'commission' => $commission,
            'tax' => $tax,
            'check' => $check,
            'owes' => $owes,
            'check_number' => $checkNumber,
        ];
    }
}
