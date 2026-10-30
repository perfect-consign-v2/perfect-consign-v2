<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Vendor;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class VendorHomePageTest extends TestCase
{
    public function testHoldOpenDeskBoothAndLastCheckBranchesRender(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        $page = [
            'name' => 'Ada',
            'hold' => true,
            'open' => false,
            'payable' => 1000,
            'rent_label' => 'Rent',
            'rent' => 200,
            'check' => 800,
            'desk' => 50,
            'booth' => ['code' => 'A1', 'rent' => 2500, 'note' => 'Corner'],
            'others' => [['code' => 'B2', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'rent' => 1000]],
            'activity' => [
                ['date' => '2026-10-01', 'label' => 'Bought at the store', 'amount' => -100, 'run_id' => ''],
                ['date' => '2026-10-02', 'label' => 'Check', 'amount' => 800, 'run_id' => '01900000-0000-7000-8000-0000000000c1'],
            ],
            'last' => [
                'run_id' => '01900000-0000-7000-8000-0000000000c1',
                'paid_on' => '2026-10-02',
                'amount' => 800,
                'number' => '12',
            ],
        ];
        ob_start();
        $view->render('Feature/Vendor/home', [
            'slug' => 'demo',
            'page' => $page,
            'cycleDay' => 15,
            'previewAs' => '01900000-0000-7000-8000-0000000000aa',
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);
        self::assertStringContainsString('The store is not writing a check', $html);
        self::assertStringContainsString('day 15 of the rent cycle', $html);
        self::assertStringContainsString('You still owe', $html);
        self::assertStringContainsString('No. 12', $html);
        self::assertStringContainsString('Corner', $html);
        self::assertStringContainsString('B2', $html);
        self::assertStringContainsString('class="draw-amount">-$1.00', $html);
        self::assertStringNotContainsString('class="draw-amount">$8.00', $html);
        self::assertMatchesRegularExpression('/<a[^>]*>\\s*Check<\\/a>/', $html);
        self::assertDoesNotMatchRegularExpression('/<a[^>]*>\\s*Bought at the store/', $html);
        self::assertStringContainsString(' to ', $html);
        self::assertStringContainsString('$25.00', $html);
        self::assertStringContainsString('as=', $html);
        $quiet = $page;
        $quiet['desk'] = 0;
        $quiet['booth'] = ['code' => 'A1', 'rent' => null, 'note' => ''];
        $quiet['others'] = [['code' => 'B2', 'starts_on' => '2026-09-01', 'ends_on' => '', 'rent' => null]];
        $quiet['last'] = null;
        $quiet['activity'] = [];
        ob_start();
        $view->render('Feature/Vendor/home', [
            'slug' => 'demo',
            'page' => $quiet,
            'cycleDay' => 1,
            'previewAs' => '',
        ]);
        $plain = ob_get_clean();
        self::assertIsString($plain);
        self::assertStringNotContainsString('You still owe', $plain);
        self::assertStringNotContainsString('$25.00', $plain);
        self::assertStringNotContainsString(' to ', $plain);
        self::assertStringContainsString('No check has been written.', $plain);
    }

    public function testInventorySearchAndEmptyBrandRender(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        $item = [
            'id' => '01900000-0000-7000-8000-0000000000i1',
            'code' => 't1',
            'barcode' => 'b1',
            'description' => 'Coat',
            'price_minor' => 1000,
            'quantity' => 1,
            'brand_name' => '',
            'color_name' => '',
            'category_name' => '',
            'size_name' => '',
            'condition_name' => '',
            'department_name' => '',
            'intake_on' => '2026-09-01',
            'expires_on' => '2026-12-01',
            'take_label' => '0',
            'overridden' => true,
        ];
        $named = $item;
        $named['id'] = '01900000-0000-7000-8000-0000000000i2';
        $named['brand_name'] = 'Wool';
        $named['code'] = 't2';
        ob_start();
        $view->render('Feature/Vendor/items', [
            'slug' => 'demo',
            'csrf' => 'token',
            'query' => 'Coat',
            'stock' => 'out',
            'unsold' => 1,
            'total' => 2,
            'items' => [$item, $named],
            'attributeLists' => [],
            'labelStock' => 'avery_5160',
            'stocks' => [['key' => 'avery_5160', 'label' => '5160']],
            'showBrand' => true,
            'previewAs' => '01900000-0000-7000-8000-0000000000aa',
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);
        self::assertStringContainsString('aria-current="page"', $html);
        self::assertStringContainsString('name="stock" value="out"', $html);
        self::assertStringContainsString('Your items with quantity zero', $html);
        self::assertStringContainsString('sticker-job-shell', $html);
        self::assertStringNotContainsString('Check items, then print from the bar at the bottom.', $html);
        self::assertStringContainsString('Search tag, barcode, description, or brand', $html);
        self::assertStringContainsString('&amp;q=Coat', $html);
        self::assertStringContainsString('stock=out&amp;q=Coat', $html);
        self::assertDoesNotMatchRegularExpression('/href="[^"]*stock=&amp;q=Coat/', $html);
        self::assertStringContainsString('>Brand<', $html);
        self::assertStringContainsString('class="muted">—', $html);
        self::assertStringContainsString('Wool', $html);
        self::assertStringContainsString('12-01-2026', $html);
        self::assertStringContainsString('id="stickers"', $html);
        self::assertStringContainsString('inventory-selection-bar', $html);
        self::assertStringContainsString('Overridden', $html);
        self::assertStringContainsString('name="as"', $html);
        ob_start();
        $view->render('Feature/Vendor/items', [
            'slug' => 'demo',
            'csrf' => 'token',
            'query' => '',
            'stock' => 'out',
            'unsold' => 1,
            'total' => 1,
            'items' => [$named],
            'attributeLists' => [],
            'labelStock' => 'avery_5160',
            'stocks' => [['key' => 'avery_5160', 'label' => '5160']],
            'showBrand' => false,
            'previewAs' => '',
        ]);
        $plain = ob_get_clean();
        self::assertIsString($plain);
        self::assertStringContainsString('Search tag, barcode, or description', $plain);
        self::assertStringNotContainsString('&amp;q=', $plain);
        self::assertStringNotContainsString('>Brand<', $plain);
        ob_start();
        $view->render('Feature/Vendor/items', [
            'slug' => 'demo',
            'csrf' => 'token',
            'query' => '',
            'stock' => 'unprinted',
            'unsold' => 0,
            'unprinted' => 1,
            'total' => 1,
            'items' => [$named],
            'attributeLists' => [],
            'labelStock' => 'avery_5160',
            'stocks' => [['key' => 'avery_5160', 'label' => '5160']],
            'showBrand' => false,
            'previewAs' => '',
        ]);
        $stickers = ob_get_clean();
        self::assertIsString($stickers);
        self::assertStringContainsString('Your items that still need a sticker printed.', $stickers);
        self::assertStringContainsString('Check items, then print from the bar at the bottom.', $stickers);
        self::assertStringContainsString('sticker-job-shell', $stickers);
        self::assertStringContainsString('form="stickers"', $stickers);
        ob_start();
        $view->render('Feature/Vendor/items', [
            'slug' => 'demo',
            'csrf' => 'token',
            'query' => 'Coat',
            'stock' => 'out',
            'unsold' => 1,
            'total' => 1,
            'items' => [],
            'attributeLists' => [],
            'labelStock' => 'avery_5160',
            'stocks' => [['key' => 'avery_5160', 'label' => '5160']],
            'showBrand' => true,
            'previewAs' => '',
        ]);
        $empty = ob_get_clean();
        self::assertIsString($empty);
        self::assertStringContainsString('No items match.', $empty);
        self::assertStringNotContainsString('id="stickers"', $empty);
        self::assertStringNotContainsString('inventory-selection-bar', $empty);
        ob_start();
        $view->render('Feature/Vendor/sales', [
            'slug' => 'demo',
            'span' => ['from' => '2026-10-01', 'to' => '2026-10-17', 'label' => 'This month'],
            'rows' => [],
            'total' => 0,
            'previewAs' => '01900000-0000-7000-8000-0000000000aa',
        ]);
        $sales = ob_get_clean();
        self::assertIsString($sales);
        self::assertStringContainsString('range=yesterday', $sales);
        self::assertStringContainsString('name="as"', $sales);
    }
}
