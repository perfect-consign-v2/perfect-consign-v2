<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Mall;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class SettingsPageTest extends TestCase
{
    public function testPayoutTimingIsLastMonth(): void
    {
        $html = $this->page('money');
        self::assertStringContainsString('Rent starts on the 1st. A period is a calendar month.', $html);
        self::assertStringContainsString('id="late_fees_enabled" name="late_fees_enabled" type="checkbox" value="1">', $html);
        $day = $this->page('money', ['cycleDay' => 28, 'lateFees' => true]);
        self::assertStringContainsString('Rent starts on the 28th.', $day);
        self::assertStringContainsString('id="late_fees_enabled" name="late_fees_enabled" type="checkbox" value="1" checked>', $day);
        self::assertStringContainsString('Pay vendors their sales. Collect rent at the register.', $html);
        self::assertStringContainsString('Store stock', $html);
        self::assertStringNotContainsString('name="booth_rentals_enabled"', $html);
        self::assertStringNotContainsString('name="consignment_enabled"', $html);
        self::assertStringContainsString('name="rent_cycle_day"', $html);
        self::assertStringNotContainsString('Weekly', $html);
        self::assertStringNotContainsString('Every two weeks', $html);
        self::assertStringContainsString('name="cash_round_minor"', $html);
        self::assertStringContainsString('No cash rounding', $html);
        $nickel = $this->page('money', ['cashRound' => '5']);
        self::assertStringContainsString('value="5" selected', $nickel);
        self::assertDoesNotMatchRegularExpression('/value="0"[^>]*selected/', $nickel);
        self::assertDoesNotMatchRegularExpression('/value="10"[^>]*selected/', $nickel);
        self::assertStringNotContainsString('name="rent_prepaid_from_prior_sales" type="checkbox" value="1" checked', $html);
        self::assertStringNotContainsString('name="receipt_footer"', $html);
        self::assertStringNotContainsString('name="booth_width_ft"', $html);
    }

    public function testBuildingShowsClockAndCatalogs(): void
    {
        $html = $this->page('building');
        self::assertStringContainsString('value="America/Los_Angeles" selected', $html);
        self::assertStringContainsString('value="sheet-30" selected', $html);
        self::assertStringNotContainsString('name="use_color" type="checkbox" value="1" checked', $html);
        self::assertStringContainsString('name="use_brand" type="checkbox" value="1"', $html);
        self::assertStringContainsString('Brand', $html);
        self::assertStringContainsString('switch-knob', $html);
        self::assertStringNotContainsString('name="default_store_take"', $html);
        self::assertStringNotContainsString('name="receipt_footer"', $html);
        self::assertStringNotContainsString('name="operating_mode"', $html);
    }

    public function testMoneyShowsOperatingMode(): void
    {
        $html = $this->page('money');
        self::assertStringContainsString('value="both" selected', $html);
        self::assertStringContainsString('name="default_store_take"', $html);
    }

    public function testReceiptShowsTheFooterField(): void
    {
        $html = $this->page('receipt');
        self::assertStringContainsString('name="receipt_footer"', $html);
        self::assertStringContainsString('name="settings_part" value="receipt"', $html);
        self::assertStringNotContainsString('name="default_store_take"', $html);
        self::assertStringNotContainsString('name="booth_width_ft"', $html);
    }

    public function testTheReceiptFooterIsShownInTheField(): void
    {
        $html = $this->page('receipt', ['receiptFooter' => "All sales final\nThank you"]);

        self::assertStringContainsString('id="receipt_footer"', $html);
        self::assertStringContainsString("All sales final\nThank you", $html);
        self::assertStringContainsString('maxlength="400"', $html);
    }

    public function testAFrozenCycleDayHasNoInput(): void
    {
        $html = $this->page('money', [
            'boothRentals' => true,
            'cycleDay' => 15,
            'cycleFrozen' => true,
            'rentPrepaid' => true,
        ]);

        self::assertStringContainsString('Cycle day 15', $html);
        self::assertStringNotContainsString('name="rent_cycle_day"', $html);
        self::assertStringContainsString('Rent starts on the 15th. A period runs the 15th through the 14th.', $html);
        self::assertStringContainsString('The previous period&#039;s sales pay this period&#039;s rent.', $html);
        self::assertStringContainsString('name="rent_prepaid_from_prior_sales" type="checkbox" value="1" checked', $html);
    }

    public function testRentalsOffHidesTheDayAndTheDeductSwitch(): void
    {
        $html = $this->page('money', [
            'boothRentals' => false,
            'cycleDay' => 15,
            'cycleFrozen' => false,
            'rentPrepaid' => true,
        ]);

        self::assertStringContainsString('This mall does not charge booth rent.', $html);
        self::assertStringNotContainsString('name="rent_cycle_day"', $html);
        self::assertStringNotContainsString('name="rent_prepaid_from_prior_sales"', $html);
    }

    public function testConsignmentSettingsDropBooths(): void
    {
        $money = $this->page('money', [
            'operatingMode' => 'consignor',
            'boothRentals' => false,
            'consignmentEnabled' => true,
        ]);
        self::assertStringContainsString('value="consignor" selected', $money);
        self::assertStringContainsString('saved on a consignor', $money);
        self::assertStringNotContainsString('name="rent_cycle_day"', $money);
        self::assertStringNotContainsString('Late fees', $money);
        self::assertStringNotContainsString('This mall does not charge booth rent.', $money);

        $building = $this->page('building', [
            'operatingMode' => 'consignor',
            'boothRentals' => false,
            'consignmentEnabled' => true,
        ]);
        self::assertStringContainsString('The store clock', $building);
        self::assertStringContainsString('name="floor_days"', $building);
        self::assertStringContainsString('type="hidden" name="booth_width_ft"', $building);
        self::assertStringNotContainsString('Default width', $building);

        $receipt = $this->page('receipt', [
            'operatingMode' => 'consignor',
            'boothRentals' => false,
            'consignmentEnabled' => true,
        ]);
        self::assertStringNotContainsString('This mall&#039;s Gmail', $receipt);
        self::assertStringContainsString('This store&#039;s Gmail', $receipt);
    }

    public function testAnEmptyNightlyLineStaysHidden(): void
    {
        $empty = $this->page('backup', ['nightlyHeadline' => '']);
        self::assertDoesNotMatchRegularExpression('/Last backup you downloaded:.*<\\/p>\\s*<p><\\/p>/s', $empty);
        $shown = $this->page('backup', ['nightlyHeadline' => 'Copied last night']);
        self::assertStringContainsString('Copied last night', $shown);
        self::assertStringNotContainsString('name="default_store_take"', $shown);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function page(string $part, array $extra = []): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Mall/settings', [
            'slug' => 'demo',
            'csrf' => 'token',
            'settingsPart' => $part,
            'timeZone' => 'America/Los_Angeles',
            'timeZones' => ['America/Los_Angeles'],
            'boothWidth' => 10,
            'boothDepth' => 10,
            'floorWidth' => 120,
            'floorDepth' => 90,
            'storeTake' => '25',
            'floorDays' => '',
            'cashRound' => '',
            'taxRate' => '',
            'backupHeadline' => 'None yet',
            'catalogs' => [],
            'stocks' => [['key' => 'sheet-30', 'label' => '30-up sheet']],
            'labelStock' => 'sheet-30',
            'operatingMode' => 'both',
            'rentPrepaid' => false,
            ...$extra,
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}

// readLateFees( graceDays( feeAmounts( optionalMinor( previewAs( keepFlash( takeFlash(
