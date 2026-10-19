<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Inventory;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class StickerJobTest extends TestCase
{
    public function testAListPrintKeepsTheDefaultActionAndTheSheetId(): void
    {
        $html = $this->render([
            'printId' => '',
            'labelStock' => 'jewelry',
            'selectionStorageKey' => 'picked',
            'labelAction' => '/demo/custom-labels',
        ]);

        self::assertStringContainsString('id="stickers"', $html);
        self::assertStringContainsString('action="/demo/custom-labels"', $html);
        self::assertStringContainsString('data-sticker-stock-key="picked"', $html);
        self::assertStringContainsString('value="jewelry" selected', $html);
        self::assertStringNotContainsString('value="sheet" selected', $html);
        self::assertStringContainsString('name="selection_storage_key" value="picked"', $html);
        self::assertStringContainsString('data-inventory-selection-clear', $html);
        self::assertStringNotContainsString('name="item"', $html);
    }

    public function testAnItemPrintPostsThatItemAndSkipsTheSheetId(): void
    {
        $html = $this->render([
            'printId' => 'item-1',
            'labelStock' => 'sheet',
            'selectionStorageKey' => 'picked',
        ]);

        self::assertStringNotContainsString('id="stickers"', $html);
        self::assertStringContainsString('action="/demo/inventory/labels"', $html);
        self::assertStringContainsString('name="item" value="item-1"', $html);
        self::assertStringNotContainsString('name="selection_storage_key"', $html);
        self::assertStringNotContainsString('data-inventory-selection-clear', $html);
        self::assertStringContainsString('value="sheet" selected', $html);
    }

    public function testABlankSelectionDoesNotPostAStorageKey(): void
    {
        $html = $this->render([
            'printId' => '',
            'labelStock' => 'sheet',
            'selectionStorageKey' => '',
            'labelAction' => '',
        ]);

        self::assertStringContainsString('action="/demo/inventory/labels"', $html);
        self::assertStringNotContainsString('data-sticker-stock-key', $html);
        self::assertStringNotContainsString('name="selection_storage_key"', $html);
        self::assertStringNotContainsString('data-inventory-selection-clear', $html);
    }

    public function testANonStringSelectionAndActionStayAtTheDefaults(): void
    {
        $html = $this->render([
            'printId' => '',
            'labelStock' => 'sheet',
            'selectionStorageKey' => 12,
            'labelAction' => 7,
        ]);

        self::assertStringContainsString('action="/demo/inventory/labels"', $html);
        self::assertStringNotContainsString('data-sticker-stock-key', $html);
        self::assertStringNotContainsString('value="12"', $html);
        self::assertStringNotContainsString('value="7"', $html);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(array $data): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Inventory/sticker-job', $data + [
            'slug' => 'demo',
            'csrf' => 'token',
            'lead' => 'Print the chosen items.',
            'stocks' => [
                ['key' => 'sheet', 'label' => 'Sheet'],
                ['key' => 'jewelry', 'label' => 'Jewelry'],
            ],
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
