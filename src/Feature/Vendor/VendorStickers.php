<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Inventory\InventoryStore;
use PerfectConsign\Feature\Inventory\LabelRequest;
use PerfectConsign\Feature\Inventory\LabelSheet;
use PerfectConsign\Feature\Inventory\StickerItem;
use PerfectConsign\Feature\Inventory\StickerSheet;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

final class VendorStickers
{
    public function __construct(
        private readonly MallApp $app,
        private readonly VendorAccount $account,
        private readonly InventoryStore $items,
        private readonly MallSettings $mall,
        private readonly LabelRequest $jobs,
        private readonly LabelSheet $sheets,
    ) {
    }

    public function printOwn(string $slug, string $vendorId): void
    {
        if ($vendorId === '') {
            $this->app->notFound();
        }
        try {
            $sheet = $this->sheet($this->storeName($slug), $vendorId);
        } catch (\InvalidArgumentException $error) {
            $this->app->redirect('/' . $slug . '/vendor/inventory', null, $error->getMessage());
        }
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Inventory/stickers', $this->jobs->withSelectionKey([
            'title' => 'Print stickers',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'sheet' => $sheet,
            'back' => '/' . $tenant->slug . '/vendor/inventory',
        ], $this->app->request()->field('selection_storage_key')));
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
            $items[] = $this->sticker($row, $store);
        }

        return $this->sheets->compose($job['stock'], $items, $job['copies'], $job['row'], $job['column']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function sticker(array $row, string $store): StickerItem
    {
        $brand = $row['brand_name'] ?? null;

        return new StickerItem(
            RowValue::string($row['barcode'] ?? null),
            View::money(RowValue::integer($row['price_minor'] ?? null)),
            RowValue::string($row['description'] ?? null),
            $store,
            RowValue::string($row['vendor_code'] ?? null),
            is_string($brand) ? $brand : '',
            $this->intake(RowValue::string($row['intake_on'] ?? null)),
            RowValue::string($row['code'] ?? null),
        );
    }

    private function intake(string $day): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if ($date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $day) {
            return $date->format('m-d-Y');
        }

        return $day;
    }
}
