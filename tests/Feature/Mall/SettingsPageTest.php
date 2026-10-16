<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Mall;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class SettingsPageTest extends TestCase
{
    public function testPayoutTimingIsLastMonth(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Mall/settings', [
            'slug' => 'demo',
            'csrf' => 'token',
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
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertStringContainsString('Rent starts on the 1st. A period is a calendar month.', $html);
        self::assertStringContainsString('id="late_fees_enabled" name="late_fees_enabled" type="checkbox" value="1">', $html);
        $day = $this->page(['cycleDay' => 28, 'lateFees' => true]);
        self::assertStringContainsString('Rent starts on the 28th.', $day);
        self::assertStringContainsString('id="late_fees_enabled" name="late_fees_enabled" type="checkbox" value="1" checked>', $day);
        self::assertStringContainsString('Pay vendors their sales. Collect rent at the register.', $html);
        self::assertStringContainsString('Booth rentals', $html);
        self::assertStringContainsString('name="rent_cycle_day"', $html);
        self::assertStringNotContainsString('Weekly', $html);
        self::assertStringNotContainsString('Every two weeks', $html);
        self::assertStringContainsString('value="America/Los_Angeles" selected', $html);
        self::assertStringContainsString('value="both" selected', $html);
        self::assertStringContainsString('value="sheet-30" selected', $html);
        self::assertStringNotContainsString('name="rent_prepaid_from_prior_sales" type="checkbox" value="1" checked', $html);
        self::assertStringNotContainsString('name="cash_round_nickel" type="checkbox" value="1" checked', $html);
        self::assertStringNotContainsString('name="use_color" type="checkbox" value="1" checked', $html);
        self::assertStringContainsString('name="use_brand" type="checkbox" value="1"', $html);
        self::assertStringContainsString('Brand', $html);
        self::assertStringContainsString('switch-knob', $html);
    }

    public function testAFrozenCycleDayHasNoInput(): void
    {
        $html = $this->page([
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
        $html = $this->page([
            'boothRentals' => false,
            'cycleDay' => 15,
            'cycleFrozen' => false,
            'rentPrepaid' => true,
        ]);

        self::assertStringContainsString('This mall does not charge booth rent.', $html);
        self::assertStringNotContainsString('name="rent_cycle_day"', $html);
        self::assertStringNotContainsString('name="rent_prepaid_from_prior_sales"', $html);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function page(array $extra = []): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Mall/settings', [
            'slug' => 'demo',
            'csrf' => 'token',
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
