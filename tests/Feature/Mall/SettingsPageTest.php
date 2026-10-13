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
        self::assertStringContainsString('Vendors are paid for last month.', $html);
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
}
