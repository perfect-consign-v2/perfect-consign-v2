<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Scalar\RowValue;

final class InventoryController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly InventoryStore $items,
        private readonly IntakeCatalog $catalog,
        private readonly PieceForm $forms,
        private readonly PieceWriter $writer,
        private readonly StoreTake $take,
        private readonly MallSettings $mall,
        private readonly LabelCatalog $stocks,
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
        ), null, $this->savedPiece());
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
            $this->app->redirect('/' . $slug . '/inventory', 'Piece deleted.');
        }
        $this->list($slug, $error);
    }

    private function change(string $slug, ?string $id): void
    {
        $this->app->open($slug, true);
        $draft = $this->draft($id);
        $error = null;
        $saved = '';
        try {
            $saved = $this->write($id, $this->writer->entry($draft));
        } catch (DatabaseRefusal $refusal) {
            $error = $this->itemError($this->app->catchRefusal($refusal));
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error !== null || $saved === '') {
            $this->form($slug, $this->forms->posted($draft), $error ?? 'That piece was not saved.');

            return;
        }
        if ($id === null) {
            $intake = $this->forms->date($draft['intake_on']) ?? $this->mall->today();
            $this->app->redirect(
                '/' . $slug . '/inventory/new?' . http_build_query([
                    'vendor' => $draft['vendor_id'],
                    'intake' => $intake,
                    'print' => $saved,
                ]),
                'Piece created.'
            );
        }
        $this->app->redirect('/' . $slug . '/inventory/' . $saved . '/edit', 'Piece saved.');
    }

    private function write(?string $id, PieceEntry $piece): string
    {
        if ($id === null) {
            return $this->items->add($piece, $this->mall->today());
        }
        $this->items->save($id, $piece, $this->mall->today());

        return $id;
    }

    private function savedPiece(): string
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
            'attributes' => $this->postedAttributes(),
            'store_take' => $this->app->request()->field('store_take'),
            'intake_on' => $this->app->request()->field('intake_on'),
            'expires_on' => $this->app->request()->field('expires_on'),
            'fill_expire' => '',
        ];
    }

    /**
     * @return array<string, array{id: string, label: string}>
     */
    private function postedAttributes(): array
    {
        $fields = [];
        foreach (NameList::all() as $list) {
            $fields[$list->key] = [
                'id' => $this->app->request()->field($list->key . '_id'),
                'label' => $this->app->request()->field($list->key . '_label'),
            ];
        }

        return $fields;
    }

    /**
     * @return list<NameList>
     */
    private function activeLists(): array
    {
        $lists = [];
        foreach (NameList::all() as $list) {
            if ($this->mall->enabled($list->flag)) {
                $lists[] = $list;
            }
        }

        return $lists;
    }

    private function list(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $query = trim($this->app->request()->query('q'));
        $items = $this->withUse($this->items->items($query));
        $this->app->show('Feature/Inventory/inventory', [
            'title' => 'Inventory',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'items' => $items,
            'query' => $query,
            'total' => $query === '' ? count($items) : $this->items->count(),
            'confirm' => $this->confirming($items, $this->app->request()->query('confirm')),
            'attributeLists' => $this->activeLists(),
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
     * } $piece
     */
    private function form(string $slug, array $piece, ?string $error, string $printId = ''): void
    {
        $tenant = $this->app->open($slug, true);
        $days = $this->mall->floorDays();
        $sold = $piece['id'] !== '' && $this->items->sold($piece['id']);
        $this->app->show('Feature/Inventory/inventory-form', [
            'title' => $piece['id'] !== '' ? 'Edit piece' : 'New piece',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'piece' => $this->forms->view($piece),
            'floorDays' => $days === null ? '' : (string) $days,
            'vendorsUrl' => '/' . $tenant->slug . '/inventory/vendors.json',
            'brandsUrl' => '/' . $tenant->slug . '/inventory/brands.json',
            'brandCreateUrl' => '/' . $tenant->slug . '/inventory/brands',
            'attributeLists' => $this->activeLists(),
            'labelStock' => $this->mall->getLabelStock(),
            'stocks' => $this->stocks->choices(),
            'printId' => $printId,
            'sold' => $sold,
            'error' => $error,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function withUse(array $items): array
    {
        $held = $this->items->referenced();
        $marked = [];
        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            $expires = $item['expires_on'] ?? null;
            $brand = $item['brand_name'] ?? null;
            $item['deletable'] = is_string($id) && !isset($held[$id]);
            $item['vendor_label'] = $this->catalog->label(
                RowValue::string($item['vendor_code'] ?? null),
                RowValue::string($item['vendor_name'] ?? null),
            );
            $item['barcode'] = RowValue::string($item['barcode'] ?? null);
            $item['quantity'] = RowValue::integer($item['quantity'] ?? null);
            $item['intake_on'] = RowValue::string($item['intake_on'] ?? null);
            $item['expires_on'] = is_string($expires) ? $expires : '';
            $item['take_label'] = $this->take->format(RowValue::integer($item['take_bps'] ?? null));
            $item['overridden'] = ($item['commission_bps'] ?? null) !== null;
            $item['brand_name'] = is_string($brand) ? $brand : '';
            foreach (NameList::all() as $list) {
                $name = $item[$list->key . '_name'] ?? null;
                $item[$list->key . '_name'] = is_string($name) ? $name : '';
            }
            $marked[] = $item;
        }

        return $marked;
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
            return 'That piece is in use.';
        }

        return $message;
    }
}
