<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

final class LabelsController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly InventoryStore $items,
        private readonly MallSettings $mall,
        private readonly LabelRequest $jobs,
        private readonly LabelSheet $sheets,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory/labels', ['POST'])]
    public function preview(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        try {
            $sheet = $this->sheet($tenant->displayName);
        } catch (\InvalidArgumentException $error) {
            $this->app->redirect($this->back($slug), null, $error->getMessage());
        }
        $this->app->show('Feature/Inventory/stickers', $this->jobs->withSelectionKey([
            'title' => 'Print stickers',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'sheet' => $sheet,
        ], $this->app->request()->field('selection_storage_key')));
    }

    private function sheet(string $store): StickerSheet
    {
        $job = $this->jobs->read($this->app->request()->post, $this->mall->getLabelStock());
        $items = [];
        foreach ($this->items->forLabels($job['ids']) as $row) {
            $items[] = $this->item($row, $store);
        }
        $this->items->markStickersPrinted($job['ids']);

        return $this->sheets->compose($job['stock'], $items, $job['copies'], $job['row'], $job['column']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function item(array $row, string $store): StickerItem
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
            is_string($row['deal_name'] ?? null) ? $row['deal_name'] : '',
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

    private function back(string $slug): string
    {
        $path = parse_url($this->app->request()->referer, PHP_URL_PATH);
        $list = '/' . $slug . '/inventory';
        if (!is_string($path) || !str_starts_with($path, $list) || str_contains($path, '/labels')) {
            return $list;
        }

        return $path;
    }
}
