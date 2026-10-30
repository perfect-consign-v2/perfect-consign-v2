<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Inventory;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class InventoryListPageTest extends TestCase
{
    public function testUnprintedListUsesPrintDockWithoutAboveTableCard(): void
    {
        $html = $this->render('unprinted');

        self::assertStringContainsString('Items that still need a sticker printed.', $html);
        self::assertStringContainsString('Check items, then print from the bar at the bottom.', $html);
        self::assertStringContainsString('sticker-job-shell', $html);
        self::assertStringContainsString('inventory-selection-bar', $html);
        self::assertStringContainsString('form="stickers"', $html);
        self::assertStringContainsString('name="copies"', $html);
        self::assertStringContainsString('data-sticker-stock-key=', $html);
        self::assertDoesNotMatchRegularExpression(
            '/card-head[^>]*>\s*<div class="h3">Print stickers/',
            $html
        );
    }

    public function testAllListOmitsNeedStickersCueAndKeepsShell(): void
    {
        $html = $this->render('');

        self::assertStringContainsString('One row is one item on the floor.', $html);
        self::assertStringNotContainsString('Check items, then print from the bar at the bottom.', $html);
        self::assertStringContainsString('sticker-job-shell', $html);
        self::assertStringContainsString('inventory-selection-bar', $html);
    }

    private function render(string $stock): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Inventory/inventory', [
            'slug' => 'demo',
            'csrf' => 'token',
            'query' => '',
            'stock' => $stock,
            'available' => 1,
            'unsold' => 0,
            'unprinted' => 1,
            'total' => 1,
            'confirm' => '',
            'items' => [[
                'id' => '01900000-0000-7000-8000-000000000001',
                'code' => 'T1',
                'barcode' => 'BC1',
                'description' => 'Coat',
                'price_minor' => 1000,
                'quantity' => 1,
                'vendor_id' => '',
                'vendor_label' => '',
                'brand_name' => '',
                'color_name' => '',
                'category_name' => '',
                'size_name' => '',
                'condition_name' => '',
                'department_name' => '',
                'intake_on' => '2026-10-01',
                'expires_on' => '',
                'take_label' => '40',
                'overridden' => false,
                'deletable' => true,
            ]],
            'attributeLists' => [],
            'labelStock' => 'avery_5160',
            'stocks' => [['key' => 'avery_5160', 'label' => '5160']],
            'showBrand' => true,
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
