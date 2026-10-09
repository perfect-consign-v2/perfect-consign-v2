<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class PayoutPageTest extends TestCase
{
    public function testTheVendorPageShowsRentTakenFromTheShare(): void
    {
        $html = $this->render('vendor-1', [
            'payable' => 1000,
            'rent' => 400,
            'check' => 600,
            'owes' => 50,
            'settled' => 400,
            'rent_from_sales' => true,
        ], 'run-1', ['by' => 'Ada', 'reason' => 'wrong window', 'at' => '2026-10-02']);

        self::assertStringContainsString('<p class="muted">Ada (A1)</p>', $html);
        self::assertStringContainsString('q=Ada&amp;standing=owed', $html);
        self::assertStringContainsString('value="vendor-1" selected', $html);
        self::assertStringContainsString('Rent from their share', $html);
        self::assertStringContainsString('Owes the store', $html);
        self::assertStringContainsString('Pay this vendor', $html);
        self::assertStringContainsString('Undone by Ada', $html);
        self::assertStringContainsString('name="run_id"', $html);
        self::assertStringContainsString('>B2<', $html);
    }

    public function testTheVendorPageCollectsOpenRentWhenNothingIsOwedFromSales(): void
    {
        $html = $this->render('vendor-1', [
            'payable' => 0,
            'rent' => 400,
            'check' => 0,
            'owes' => 400,
            'settled' => 0,
            'rent_from_sales' => false,
        ], '', null);

        self::assertStringContainsString('Collect rent', $html);
        self::assertStringContainsString('Nothing to pay.', $html);
        self::assertStringContainsString('Open rent', $html);
        self::assertStringNotContainsString('Undo this payout', $html);
    }

    public function testACheckWithNoSettledRentCanStillBePaid(): void
    {
        $html = $this->render('vendor-1', [
            'payable' => 1000,
            'rent' => 0,
            'check' => 600,
            'owes' => 0,
            'settled' => 0,
            'rent_from_sales' => false,
        ], '', null);

        self::assertStringContainsString('data-open-modal="confirm-payout"', $html);
        self::assertStringContainsString('Open rent', $html);
        self::assertStringNotContainsString('Collect rent', $html);
        self::assertStringNotContainsString('Owes the store', $html);
    }

    public function testRentFromSalesDoesNotCollectWhenNothingIsOwed(): void
    {
        $html = $this->render('vendor-1', [
            'payable' => 1000,
            'rent' => 0,
            'check' => 0,
            'owes' => 0,
            'settled' => 0,
            'rent_from_sales' => true,
        ], '', null);

        self::assertStringContainsString('Nothing to pay.', $html);
        self::assertStringNotContainsString('Collect rent', $html);
    }

    public function testOneVendorHasNoPicker(): void
    {
        $html = $this->render('vendor-1', null, '', null, [
            ['id' => 'vendor-1', 'code' => 'A1', 'name' => 'Ada'],
        ]);

        self::assertStringNotContainsString('id="vendor"', $html);
        self::assertStringContainsString('<p class="muted">Ada (A1)</p>', $html);
    }

    public function testAnUnknownVendorHasNoName(): void
    {
        $html = $this->render('missing', [
            'payable' => 1000,
            'rent' => 0,
            'check' => 600,
            'owes' => 0,
            'settled' => 0,
            'rent_from_sales' => false,
        ], '', null);

        self::assertStringNotContainsString('<p class="muted">', $html);
        self::assertStringNotContainsString('<p></p>', $html);
        self::assertStringContainsString('Confirm this payout', $html);
    }

    public function testAVendorWithNoOpenPayoutSaysSo(): void
    {
        $html = $this->render('vendor-1', null, '', null);

        self::assertStringContainsString('No open payout for this vendor.', $html);
    }

    /**
     * @param array{payable: int, rent: int, check: int, owes: int, settled: int, rent_from_sales: bool}|null $open
     * @param array{by: string, reason: string, at: string}|null $undone
     * @param list<array{id: string, code: string, name: string}>|null $vendors
     */
    private function render(string $vendorId, ?array $open, string $runId, ?array $undone, ?array $vendors = null): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Payout/payout', [
            'slug' => 'webprobe',
            'csrf' => 'token',
            'vendorId' => $vendorId,
            'open' => $open,
            'runId' => $runId,
            'undone' => $undone,
            'error' => null,
            'vendors' => $vendors ?? [
                ['id' => 'vendor-1', 'code' => 'A1', 'name' => 'Ada'],
                ['id' => 'vendor-2', 'code' => 'B2', 'name' => ''],
            ],
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
