<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class VendorTemplateGapTest extends TestCase
{
    public function testConsignmentShareCheckAndClawbackBranchesRender(): void
    {
        $html = $this->render(true, [
            'check_minor' => 2500,
            'clawback_minor' => 500,
            'rent_from_sales' => false,
            'owes_minor' => 0,
            'settled_minor' => 0,
            'current' => [],
            'upcoming' => [],
            'past' => [],
        ]);

        self::assertStringContainsString('/gapmall/consignors', $html);
        self::assertStringContainsString('$25.00', $html);
        self::assertStringContainsString('Pay this consignor', $html);
        self::assertStringContainsString('They owe us (returns)', $html);
        self::assertStringContainsString('$5.00', $html);
    }

    public function testRentFromSalesOwesAndUpcomingBoothsRender(): void
    {
        $html = $this->render(false, [
            'check_minor' => 0,
            'clawback_minor' => 0,
            'rent_from_sales' => true,
            'owes_minor' => 1800,
            'settled_minor' => 900,
            'current' => [],
            'upcoming' => [[
                'id' => '01900000-0000-7000-8000-0000000000b1',
                'booth_id' => '01900000-0000-7000-8000-0000000000b2',
                'booth_code' => 'UP-1',
                'starts_on' => '2026-12-01',
                'ends_on' => '2026-12-31',
                'rent_minor' => 1000,
            ]],
            'past' => [],
        ]);

        self::assertStringContainsString('/gapmall/vendors', $html);
        self::assertStringContainsString('Rent from their share', $html);
        self::assertStringContainsString('$9.00', $html);
        self::assertStringContainsString('Owes the store', $html);
        self::assertStringContainsString('$18.00', $html);
        self::assertStringContainsString('Upcoming Booths', $html);
        self::assertStringContainsString('UP-1', $html);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function render(bool $consignmentOnly, array $overrides): string
    {
        $vendor = [
            'id' => '01900000-0000-7000-8000-0000000000c1',
            'code' => 'V1',
            'name' => 'Ada Vendor',
            'first_name' => 'Ada',
            'last_name' => 'Vendor',
            'phone' => '5551234567',
            'email' => 'ada@example.test',
            'email_bounced' => false,
            'address' => '1 Main',
            'city' => 'Bend',
            'state' => 'OR',
            'postal' => '97701',
            'status_label' => 'Active',
            'status_badge' => 'badge-ok',
            'store_take' => '40',
            'default_store_take' => '40',
            'store_take_overridden' => false,
            'agreement_starts_on' => '2026-01-01',
            'agreement_ends_on' => '',
            'tax_id' => '',
            'notes' => '',
            'tax_exempt' => false,
            'tax_exempt_number' => '',
            'payable_minor' => 0,
            'rent_minor' => 0,
            'year_paid_minor' => 0,
            'discount_by' => '',
            'keep_days' => '',
            'expiry_action' => '',
        ] + $overrides;
        $view = new View(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src');
        ob_start();
        $view->render('Feature/Party/vendor', [
            'slug' => 'gapmall',
            'csrf' => 'csrf-token',
            'today' => '2026-11-12',
            'vendor' => $vendor,
            'booths' => [[
                'id' => '01900000-0000-7000-8000-0000000000b2',
                'code' => 'A1',
                'rent' => '100.00',
                'available_on' => '',
            ]],
            'signIn' => null,
            'rentHistories' => [],
            'consignmentOnly' => $consignmentOnly,
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
