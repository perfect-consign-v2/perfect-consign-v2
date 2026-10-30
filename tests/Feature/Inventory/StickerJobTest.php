<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Inventory;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class StickerJobTest extends TestCase
{
    public function testAShellPrintKeepsTheDefaultActionAndTheSheetId(): void
    {
        $html = $this->renderJob([
            'printId' => '',
            'labelStock' => 'jewelry',
            'selectionStorageKey' => 'picked',
            'labelAction' => '/demo/custom-labels',
            'stickerChrome' => 'shell',
        ]);

        self::assertStringContainsString('id="stickers"', $html);
        self::assertStringContainsString('class="sticker-job-shell"', $html);
        self::assertStringContainsString('action="/demo/custom-labels"', $html);
        self::assertStringContainsString('name="selection_storage_key" value="picked"', $html);
        self::assertStringNotContainsString('id="stock"', $html);
        self::assertStringNotContainsString('Print stickers</div>', $html);
        self::assertStringNotContainsString('data-inventory-selection-clear', $html);
        self::assertStringNotContainsString('name="item"', $html);
    }

    public function testASelectionBarPostsSheetFieldsOntoStickers(): void
    {
        $html = $this->renderBar([
            'labelStock' => 'jewelry',
            'selectionStorageKey' => 'picked',
        ]);

        self::assertStringContainsString('data-inventory-selection-bar', $html);
        self::assertStringContainsString('form="stickers"', $html);
        self::assertStringContainsString('data-sticker-stock-key="picked"', $html);
        self::assertStringContainsString('value="jewelry" selected', $html);
        self::assertStringNotContainsString('value="sheet" selected', $html);
        self::assertStringContainsString('name="copies"', $html);
        self::assertStringContainsString('name="start_row"', $html);
        self::assertStringContainsString('name="start_column"', $html);
        self::assertStringContainsString('data-inventory-selection-clear', $html);
        self::assertStringContainsString('Print stickers', $html);
    }

    public function testAnItemPrintPostsThatItemAndSkipsTheSheetId(): void
    {
        $html = $this->renderJob([
            'printId' => 'item-1',
            'labelStock' => 'sheet',
            'selectionStorageKey' => 'picked',
        ]);

        self::assertStringNotContainsString('id="stickers"', $html);
        self::assertStringContainsString('class="card mt-4"', $html);
        self::assertStringContainsString('action="/demo/inventory/labels"', $html);
        self::assertStringContainsString('name="item" value="item-1"', $html);
        self::assertStringNotContainsString('name="selection_storage_key"', $html);
        self::assertStringNotContainsString('data-inventory-selection-clear', $html);
        self::assertStringContainsString('value="sheet" selected', $html);
        self::assertStringContainsString('id="stock"', $html);
    }

    public function testABlankSelectionDoesNotPostAStorageKey(): void
    {
        $html = $this->renderJob([
            'printId' => '',
            'labelStock' => 'sheet',
            'selectionStorageKey' => '',
            'labelAction' => '',
            'stickerChrome' => 'shell',
        ]);

        self::assertStringContainsString('action="/demo/inventory/labels"', $html);
        self::assertStringNotContainsString('data-sticker-stock-key', $html);
        self::assertStringNotContainsString('name="selection_storage_key"', $html);
        self::assertStringNotContainsString('data-inventory-selection-clear', $html);
    }

    public function testANonStringSelectionAndActionStayAtTheDefaults(): void
    {
        $html = $this->renderJob([
            'printId' => '',
            'labelStock' => 'sheet',
            'selectionStorageKey' => 12,
            'labelAction' => 7,
            'stickerChrome' => 'shell',
        ]);

        self::assertStringContainsString('action="/demo/inventory/labels"', $html);
        self::assertStringNotContainsString('data-sticker-stock-key', $html);
        self::assertStringNotContainsString('value="12"', $html);
        self::assertStringNotContainsString('value="7"', $html);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderJob(array $data): string
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

    /**
     * @param array<string, mixed> $data
     */
    private function renderBar(array $data): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Inventory/selection-bar', $data + [
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
