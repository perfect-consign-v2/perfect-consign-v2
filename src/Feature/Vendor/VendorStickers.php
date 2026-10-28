<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Inventory\InventoryStore;
use PerfectConsign\Feature\Inventory\LabelRequest;
use PerfectConsign\Feature\Inventory\LabelSheet;
use PerfectConsign\Feature\Inventory\StickerFromRow;
use PerfectConsign\Feature\Inventory\StickerSheet;
use PerfectConsign\Feature\Mall\MallSettings;

final class VendorStickers
{
    public function __construct(
        private readonly MallApp $app,
        private readonly VendorAccount $account,
        private readonly InventoryStore $items,
        private readonly MallSettings $mall,
        private readonly LabelRequest $jobs,
        private readonly LabelSheet $sheets,
        private readonly StickerFromRow $stickers,
    ) {
    }

    public function printOwn(string $slug, string $vendorId): void
    {
        if ($vendorId === '') {
            $this->app->notFound();
        }
        $inventory = $this->inventoryPath($slug);
        try {
            $sheet = $this->sheet($this->storeName($slug), $vendorId);
        } catch (\InvalidArgumentException $error) {
            $this->app->redirect($inventory, null, $error->getMessage());
        }
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Inventory/stickers', $this->jobs->withSelectionKey([
            'title' => 'Print stickers',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'sheet' => $sheet,
            'back' => $inventory,
        ], $this->app->request()->field('selection_storage_key')));
    }

    private function inventoryPath(string $slug): string
    {
        return VendorPreview::with(
            '/' . $slug . '/vendor/inventory',
            VendorPreview::token($this->app->request()->query('as'))
        );
    }

    private function storeName(string $slug): string
    {
        return $this->app->open($slug, true)->displayName;
    }

    private function sheet(string $store, string $vendorId): StickerSheet
    {
        $job = $this->jobs->read($this->app->request()->post, $this->mall->getLabelStock());
        foreach ($job['ids'] as $id) {
            if (!$this->account->owns($vendorId, $id)) {
                throw new \InvalidArgumentException('That item is not yours.');
            }
        }
        $items = [];
        foreach ($this->items->forLabels($job['ids']) as $row) {
            $items[] = $this->stickers->item($row, $store);
        }
        $this->items->markStickersPrinted($job['ids']);

        return $this->sheets->compose($job['stock'], $items, $job['copies'], $job['row'], $job['column']);
    }
}
