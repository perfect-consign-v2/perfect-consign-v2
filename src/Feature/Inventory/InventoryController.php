<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;

final class InventoryController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly InventoryStore $items,
        private readonly ItemForm $forms,
        private readonly ItemWriter $writer,
        private readonly ItemListRows $rows,
        private readonly MallSettings $mall,
        private readonly LabelCatalog $stocks,
        private readonly ItemPhotoController $photos,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory', ['GET'])]
    public function show(string $slug): void
    {
        $this->list($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory/new', ['GET'])]
    public function create(string $slug): void
    {
        $this->app->open($slug, true);
        $this->form($slug, $this->forms->blank(
            $this->app->request()->query('vendor'),
            $this->app->request()->query('intake'),
        ), null, $this->savedItem());
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory', ['POST'])]
    public function add(string $slug): void
    {
        $this->app->checkCsrf();
        $this->change($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory/([0-9a-fA-F-]{36})/edit', ['GET'])]
    public function edit(string $slug, string $id): void
    {
        $this->app->open($slug, true);
        $item = $this->items->find($id);
        if ($item === null) {
            $this->app->notFound();
        }
        $this->form($slug, $this->forms->stored($item), null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory/([0-9a-fA-F-]{36})', ['POST'])]
    public function save(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->change($slug, $id);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/inventory/([0-9a-fA-F-]{36})/delete', ['POST'])]
    public function remove(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $error = null;
        try {
            $this->items->remove($id);
        } catch (DatabaseRefusal $refusal) {
            $error = $this->itemError($this->app->catchRefusal($refusal));
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/inventory', 'Item deleted.');
        }
        $this->list($slug, $error);
    }

    private function change(string $slug, ?string $id): void
    {
        $this->app->open($slug, true);
        $draft = $this->draft($id);
        $error = null;
        $saved = '';
        $photo = null;
        try {
            if ($id === null) {
                $photo = $this->photos->accepted($this->app->request()->upload('photo'));
            }
            $saved = $this->write($id, $this->writer->entry($draft));
        } catch (DatabaseRefusal $refusal) {
            $error = $this->itemError($this->app->catchRefusal($refusal));
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error !== null || $saved === '') {
            $this->form($slug, $this->forms->posted($draft), $error ?? 'That item was not saved.');

            return;
        }
        if ($id === null) {
            $warning = $photo !== null && !$this->photos->keep($saved, $photo)
                ? 'The item was created. The photo was not saved.'
                : null;
            $intake = $this->forms->date($draft['intake_on']) ?? $this->mall->today();
            $this->app->redirect(
                '/' . $slug . '/inventory/new?' . http_build_query([
                    'vendor' => $draft['vendor_id'],
                    'intake' => $intake,
                    'print' => $saved,
                ]),
                'Item created.',
                $warning
            );
        }
        $this->app->redirect('/' . $slug . '/inventory/' . $saved . '/edit', 'Item saved.');
    }

    private function write(?string $id, ItemEntry $item): string
    {
        if ($id === null) {
            return $this->items->add($item, $this->mall->today());
        }
        $this->items->save($id, $item, $this->mall->today());

        return $id;
    }

    private function savedItem(): string
    {
        $id = $this->app->request()->query('print');
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1 || $this->items->find($id) === null) {
            return '';
        }

        return $id;
    }

    /**
     * @return array{
     *     id: string,
     *     code: string,
     *     barcode: string,
     *     description: string,
     *     price: string,
     *     quantity: string,
     *     vendor_id: string,
     *     vendor_label: string,
     *     brand_id: string,
     *     brand_label: string,
     *     attributes: array<string, array{id: string, label: string}>,
     *     store_take: string,
     *     intake_on: string,
     *     expires_on: string,
     *     fill_expire: string
     * }
     */
    private function draft(?string $id): array
    {
        return [
            'id' => $id ?? '',
            'code' => $this->app->request()->field('code'),
            'barcode' => $this->app->request()->field('barcode'),
            'description' => $this->app->request()->field('description'),
            'price' => $this->app->request()->field('price'),
            'quantity' => $this->app->request()->field('quantity'),
            'vendor_id' => $this->app->request()->field('vendor_id'),
            'vendor_label' => $this->app->request()->field('vendor_label'),
            'brand_id' => $this->app->request()->field('brand_id'),
            'brand_label' => $this->app->request()->field('brand_label'),
            'attributes' => $this->forms->postedAttributes(
                fn (string $name): string => $this->app->request()->field($name),
            ),
            'store_take' => $this->app->request()->field('store_take'),
            'intake_on' => $this->app->request()->field('intake_on'),
            'expires_on' => $this->app->request()->field('expires_on'),
            'fill_expire' => '',
            'placement' => $this->app->request()->field('placement'),
        ];
    }

    private function list(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $query = trim($this->app->request()->query('q'));
        $stock = $this->app->request()->query('stock') === 'out' ? 'out' : '';
        $items = $this->rows->mark($this->items->items($query, $stock), $this->items->referenced());
        $this->app->show('Feature/Inventory/inventory', [
            'title' => 'Items',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'items' => $items,
            'query' => $query,
            'stock' => $stock,
            'unsold' => $this->items->unsold(),
            'total' => $query === '' ? count($items) : $this->items->count(),
            'confirm' => $this->confirming($items, $this->app->request()->query('confirm')),
            'labelStock' => $this->mall->getLabelStock(),
            'stocks' => $this->stocks->choices(),
            'error' => $error,
        ]);
    }

    /**
     * @param array{
     *     id: string,
     *     code: string,
     *     barcode: string,
     *     description: string,
     *     price: string,
     *     quantity: string,
     *     vendor_id: string,
     *     vendor_label: string,
     *     brand_id: string,
     *     brand_label: string,
     *     attributes: array<string, array{id: string, label: string}>,
     *     store_take: string,
     *     intake_on: string,
     *     expires_on: string,
     *     fill_expire: string
     * } $item
     */
    private function form(string $slug, array $item, ?string $error, string $printId = ''): void
    {
        $tenant = $this->app->open($slug, true);
        $days = $this->mall->floorDays();
        $sold = $item['id'] !== '' && $this->items->sold($item['id']);
        $this->app->show('Feature/Inventory/inventory-form', [
            'title' => $item['id'] !== '' ? 'Edit item' : 'New item',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'item' => $this->forms->view($item),
            'floorDays' => $days === null ? '' : (string) $days,
            'vendorsUrl' => '/' . $tenant->slug . '/inventory/vendors.json',
            'brandsUrl' => '/' . $tenant->slug . '/inventory/brands.json',
            'brandCreateUrl' => '/' . $tenant->slug . '/inventory/brands',
            'labelStock' => $this->mall->getLabelStock(),
            'stocks' => $this->stocks->choices(),
            'printId' => $printId,
            'sold' => $sold,
            'hasPhoto' => $item['id'] !== '' && $this->photos->has($item['id']),
            'photoRule' => $this->photos->rule(),
            'error' => $error,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function confirming(array $items, string $confirm): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $confirm) !== 1) {
            return '';
        }
        $needle = strtolower($confirm);
        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            if (is_string($id) && strtolower($id) === $needle && ($item['deletable'] ?? false) === true) {
                return $id;
            }
        }

        return '';
    }

    private function itemError(string $message): string
    {
        $known = [
            'items_code_key' => 'That tag is already used.',
            'items_barcode_key' => 'That barcode is already used.',
            'items_code_chk' => 'Enter a tag.',
            'items_barcode_chk' => 'Enter a barcode of at most 100 characters.',
            'items_description_chk' => 'Enter a description.',
            'items_commission_chk' => 'store take is a percent from 0 to 100',
            'items_dates_chk' => 'The expire date is before the intake date.',
        ];
        foreach ($known as $needle => $text) {
            if (str_contains($message, $needle)) {
                return $text;
            }
        }
        if (str_contains($message, 'foreign key constraint') || str_contains($message, 'is still referenced')) {
            return 'That item is in use.';
        }

        return $message;
    }
}
