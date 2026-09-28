<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;

final class InventoryController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly InventoryStore $items,
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
        $this->form($slug, null, null);
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
        $this->form($slug, $item, null);
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
        try {
            $this->write($id, $draft);
        } catch (DatabaseRefusal $refusal) {
            $error = $this->itemError($this->app->catchRefusal($refusal));
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/inventory', $id === null ? 'Piece created.' : 'Piece saved.');
        }
        $this->form($slug, $draft, $error);
    }

    /**
     * @param array{id: string, code: string, description: string, vendor_id: string, brand_id: string, price: string} $draft
     */
    private function write(?string $id, array $draft): void
    {
        $price = Money::parseMinor($draft['price']);
        $brand = $draft['brand_id'] === '' ? null : $draft['brand_id'];
        if ($id === null) {
            $this->items->add($draft['code'], $draft['vendor_id'], $brand, $draft['description'], $price);

            return;
        }
        $this->items->save($id, $draft['code'], $draft['vendor_id'], $brand, $draft['description'], $price);
    }

    /**
     * @return array{id: string, code: string, description: string, vendor_id: string, brand_id: string, price: string}
     */
    private function draft(?string $id): array
    {
        return [
            'id' => $id ?? '',
            'code' => $this->app->request()->field('code'),
            'description' => $this->app->request()->field('description'),
            'vendor_id' => $this->app->request()->field('vendor_id'),
            'brand_id' => $this->app->request()->field('brand_id'),
            'price' => $this->app->request()->field('price'),
        ];
    }

    /**
     * @param array<string, mixed>|null $item
     */
    private function form(string $slug, ?array $item, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $id = is_array($item) ? ($item['id'] ?? null) : null;
        $this->app->show('Feature/Inventory/inventory-form', [
            'title' => is_string($id) && $id !== '' ? 'Edit piece' : 'New piece',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'item' => $item,
            'vendors' => $this->items->vendors(),
            'brands' => $this->items->brands(),
            'error' => $error,
        ]);
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
            $item['deletable'] = is_string($id) && !isset($held[$id]);
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
        if (str_contains($message, 'items_code_key')) {
            return 'That tag is already used.';
        }
        if (str_contains($message, 'items_code_chk')) {
            return 'Enter a tag.';
        }
        if (str_contains($message, 'items_description_chk')) {
            return 'Enter a description.';
        }
        if (str_contains($message, 'foreign key constraint') || str_contains($message, 'is still referenced')) {
            return 'That piece is in use.';
        }

        return $message;
    }
}
