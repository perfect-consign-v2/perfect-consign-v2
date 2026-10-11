<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Inventory;

use PerfectConsign\Feature\Inventory\InventorySelectionKey;
use PerfectConsign\Feature\Inventory\LabelCatalog;
use PerfectConsign\Feature\Inventory\LabelRequest;
use PerfectConsign\Feature\Inventory\StickerSheet;
use PHPUnit\Framework\TestCase;

final class InventorySelectionKeyTest extends TestCase
{
    public function testStaffAndVendorKeysRoundTrip(): void
    {
        $staff = InventorySelectionKey::items('webprobe');
        $vendor = InventorySelectionKey::vendor('beccas-bargains');

        self::assertSame('pc.inventorySelection.v1.items.webprobe', $staff);
        self::assertSame($staff, InventorySelectionKey::fromRequest($staff));
        self::assertSame('pc.inventorySelection.v1.vendor.beccas-bargains', $vendor);
        self::assertSame($vendor, InventorySelectionKey::fromRequest($vendor));
    }

    public function testAnExtraSuffixOrEmptyKeyIsRejected(): void
    {
        self::assertNull(InventorySelectionKey::fromRequest(''));
        self::assertNull(InventorySelectionKey::fromRequest(InventorySelectionKey::items('webprobe') . '.extra'));
        self::assertNull(InventorySelectionKey::fromRequest(InventorySelectionKey::vendor('webprobe') . '.extra'));
    }

    public function testThePrintButtonCarriesOnlyAnAllowlistedKey(): void
    {
        $jobs = new LabelRequest(new LabelCatalog());
        $sheet = new StickerSheet('8.5in', '11in', 0, []);
        $slug = 'webprobe';
        $kept = $jobs->withSelectionKey(
            ['sheet' => $sheet, 'slug' => $slug],
            InventorySelectionKey::items($slug),
        );
        $keptHtml = $this->preview($kept);
        self::assertMatchesRegularExpression(
            '/<button[^>]*data-print[^>]*data-selection-storage-key="pc\.inventorySelection\.v1\.items\.webprobe"/',
            $keptHtml,
        );
        self::assertStringContainsString('sessionStorage.removeItem', $keptHtml);

        $dropped = $jobs->withSelectionKey(
            ['sheet' => $sheet, 'slug' => $slug],
            InventorySelectionKey::items($slug) . '.extra',
        );
        self::assertArrayNotHasKey('selectionStorageKey', $dropped);
        $droppedHtml = $this->preview($dropped);
        self::assertStringNotContainsString('data-selection-storage-key', $droppedHtml);
        self::assertStringNotContainsString('sessionStorage.removeItem', $droppedHtml);

        $absent = $this->preview(['sheet' => $sheet, 'slug' => $slug]);
        self::assertStringNotContainsString('data-selection-storage-key', $absent);
        self::assertStringContainsString('>Print</button>', $absent);
    }

    public function testBackUsesTheGivenPathAndAnEmptyPathReturnsToInventory(): void
    {
        $sheet = new StickerSheet('8.5in', '11in', 0, []);
        $custom = $this->preview([
            'sheet' => $sheet,
            'slug' => 'webprobe',
            'back' => '/webprobe/vendor',
        ]);
        self::assertStringContainsString('href="/webprobe/vendor"', $custom);

        $empty = $this->preview([
            'sheet' => $sheet,
            'slug' => 'webprobe',
            'back' => '',
        ]);
        self::assertStringContainsString('href="/webprobe/inventory"', $empty);
        self::assertStringNotContainsString('href=""', $empty);
    }

    public function testANonStringStorageKeyIsOmitted(): void
    {
        $html = $this->preview([
            'sheet' => new StickerSheet('8.5in', '11in', 0, []),
            'slug' => 'webprobe',
            'selectionStorageKey' => 1,
        ]);
        self::assertStringNotContainsString('data-selection-storage-key', $html);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function preview(array $data): string
    {
        $sheet = $data['sheet'] ?? null;
        $slug = $data['slug'] ?? '';
        if (!$sheet instanceof StickerSheet || !is_string($slug)) {
            self::fail('the preview data was incomplete');
        }
        $back = $data['back'] ?? null;
        $selectionStorageKey = $data['selectionStorageKey'] ?? null;
        ob_start();
        require dirname(__DIR__, 3) . '/src/Feature/Inventory/stickers.php';
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
