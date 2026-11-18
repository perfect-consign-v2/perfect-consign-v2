<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Inventory\InventoryStore;
use PerfectConsign\Feature\Inventory\ItemForm;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Scalar\RowValue;

final class VendorItemController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly VendorPreview $preview,
        private readonly VendorItemDraft $draft,
        private readonly InventoryStore $items,
        private readonly VendorAccount $account,
        private readonly VendorItemCatalog $catalog,
        private readonly VendorPlacement $placement,
        private readonly ItemForm $forms,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/inventory/new', ['GET'])]
    public function create(string $slug): void
    {
        $vendorId = $this->vendorId($slug);
        $intake = $this->forms->date($this->app->request()->query('intake')) ?? $this->catalog->today();
        $placement = $this->defaultPlacement();
        $expires = $placement === 'consigned' ? $this->defaultExpire($intake) : '';
        $this->show($slug, $this->blank($intake, $placement, $expires), $this->printId($vendorId), null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/inventory/new', ['POST'])]
    public function add(string $slug): void
    {
        $this->app->checkCsrf();
        $vendorId = $this->vendorId($slug);
        $posted = $this->posted();
        try {
            $saved = $this->items->add($this->draft->create($posted, $vendorId, $this->catalog->today()), $this->catalog->today());
        } catch (DatabaseRefusal $refusal) {
            $this->show($slug, $this->viewFromPosted($posted), '', $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->show($slug, $this->viewFromPosted($posted), '', $error->getMessage());

            return;
        }
        $intake = $this->forms->date($posted['intake_on']) ?? $this->catalog->today();
        $this->app->redirect($this->preview->url('/' . $slug . '/vendor/inventory/new?' . http_build_query([
            'intake' => $intake,
            'print' => $saved,
        ])), 'Item created.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/inventory/([0-9a-fA-F-]{36})/edit', ['GET'])]
    public function edit(string $slug, string $id): void
    {
        $vendorId = $this->vendorId($slug);
        $row = $this->owned($vendorId, $id);
        if ($this->draft->isSoldOut($row)) {
            $this->app->redirect($this->listPath($slug), null, 'That item is sold.');
        }
        $this->show($slug, $this->fromRow($row), '', null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/inventory/([0-9a-fA-F-]{36})', ['POST'])]
    public function save(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $vendorId = $this->vendorId($slug);
        $row = $this->owned($vendorId, $id);
        $posted = $this->posted();
        if ($this->draft->isSoldOut($row)) {
            $this->app->redirect($this->listPath($slug), null, 'That item is sold.');
        }
        try {
            $this->items->save($id, $this->draft->update($row, $posted, $vendorId), $this->catalog->today());
        } catch (DatabaseRefusal $refusal) {
            $this->show($slug, $this->redisplay($row, $posted), '', $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->show($slug, $this->redisplay($row, $posted), '', $error->getMessage());

            return;
        }
        $this->app->redirect($this->preview->url('/' . $slug . '/vendor/inventory/' . $id . '/edit'), 'Item saved.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/vendor/inventory/([0-9a-fA-F-]{36})/delete', ['POST'])]
    public function remove(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $vendorId = $this->vendorId($slug);
        $row = $this->owned($vendorId, $id);
        try {
            $this->items->remove($id);
        } catch (DatabaseRefusal $refusal) {
            $this->show($slug, $this->fromRow($row), '', $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->show($slug, $this->fromRow($row), '', $error->getMessage());

            return;
        }
        $this->app->redirect($this->listPath($slug), 'Item deleted.');
    }

    private function vendorId(string $slug): string
    {
        $this->app->open($slug, true);
        if (!$this->catalog->inventoryOpen()) {
            $this->app->notFound();
        }

        return $this->preview->id($slug);
    }

    /**
     * @return array<string, mixed>
     */
    private function owned(string $vendorId, string $id): array
    {
        if (!$this->account->owns($vendorId, $id)) {
            $this->app->notFound();
        }
        $row = $this->items->find($id);
        if ($row === null) {
            $this->app->notFound();
        }

        return $row;
    }

    /**
     * @param array{
     *     id: string,
     *     description: string,
     *     price: string,
     *     quantity: string,
     *     intake_on: string,
     *     expires_on: string,
     *     placement: string,
     *     brand_id: string,
     *     brand_label: string,
     *     attributes: array<string, array{id: string, label: string}>
     * } $posted
     */
    private function show(string $slug, array $posted, string $printId, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $choices = $this->placement->choices();
        $days = $this->catalog->floorDayCount();
        $this->app->show('Feature/Vendor/vendor-item-form', [
            'title' => $posted['id'] !== '' ? 'Edit item' : 'New item',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'item' => $posted,
            'choices' => $choices,
            'floorDays' => $days === null ? '' : (string) $days,
            'labelStock' => $this->catalog->labelStock(),
            'stocks' => $this->catalog->stocks(),
            'printId' => $printId,
            'sold' => $posted['id'] !== '' && $this->items->sold($posted['id']),
            'error' => $error,
            'closed' => $choices === [] && $posted['id'] === '',
        ]);
    }

    /**
     * @return array{
     *     id: string,
     *     description: string,
     *     price: string,
     *     quantity: string,
     *     intake_on: string,
     *     expires_on: string,
     *     placement: string,
     *     brand_id: string,
     *     brand_label: string,
     *     attributes: array<string, array{id: string, label: string}>
     * }
     */
    private function blank(string $intake, string $placement, string $expires): array
    {
        return [
            'id' => '',
            'description' => '',
            'price' => '',
            'quantity' => '1',
            'intake_on' => $intake,
            'expires_on' => $expires,
            'placement' => $placement,
            'brand_id' => '',
            'brand_label' => '',
            'attributes' => [],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *     id: string,
     *     description: string,
     *     price: string,
     *     quantity: string,
     *     intake_on: string,
     *     expires_on: string,
     *     placement: string,
     *     brand_id: string,
     *     brand_label: string,
     *     attributes: array<string, array{id: string, label: string}>
     * }
     */
    private function fromRow(array $row): array
    {
        $stored = $this->forms->stored($row);

        return [
            'id' => $stored['id'],
            'description' => $stored['description'],
            'price' => $stored['price'],
            'quantity' => $stored['quantity'],
            'intake_on' => $stored['intake_on'],
            'expires_on' => $stored['expires_on'],
            'placement' => RowValue::string($row['placement'] ?? null),
            'brand_id' => $stored['brand_id'],
            'brand_label' => $stored['brand_label'],
            'attributes' => $stored['attributes'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array{
     *     description: string,
     *     price: string,
     *     quantity: string,
     *     intake_on: string,
     *     expires_on: string,
     *     placement: string,
     *     brand_id: string,
     *     brand_label: string,
     *     attributes: array<string, array{id: string, label: string}>
     * } $posted
     * @return array{
     *     id: string,
     *     description: string,
     *     price: string,
     *     quantity: string,
     *     intake_on: string,
     *     expires_on: string,
     *     placement: string,
     *     brand_id: string,
     *     brand_label: string,
     *     attributes: array<string, array{id: string, label: string}>
     * }
     */
    private function redisplay(array $row, array $posted): array
    {
        return [
            'id' => RowValue::string($row['id'] ?? null),
            'description' => $posted['description'],
            'price' => $posted['price'],
            'quantity' => $posted['quantity'],
            'intake_on' => $posted['intake_on'],
            'expires_on' => $posted['expires_on'],
            'placement' => RowValue::string($row['placement'] ?? null),
            'brand_id' => $posted['brand_id'],
            'brand_label' => $posted['brand_label'],
            'attributes' => $posted['attributes'],
        ];
    }

    /**
     * @param array{
     *     description: string,
     *     price: string,
     *     quantity: string,
     *     intake_on: string,
     *     expires_on: string,
     *     placement: string,
     *     brand_id: string,
     *     brand_label: string,
     *     attributes: array<string, array{id: string, label: string}>
     * } $posted
     * @return array{
     *     id: string,
     *     description: string,
     *     price: string,
     *     quantity: string,
     *     intake_on: string,
     *     expires_on: string,
     *     placement: string,
     *     brand_id: string,
     *     brand_label: string,
     *     attributes: array<string, array{id: string, label: string}>
     * }
     */
    private function viewFromPosted(array $posted): array
    {
        return [
            'id' => '',
            'description' => $posted['description'],
            'price' => $posted['price'],
            'quantity' => $posted['quantity'],
            'intake_on' => $posted['intake_on'],
            'expires_on' => $posted['expires_on'],
            'placement' => $posted['placement'],
            'brand_id' => $posted['brand_id'],
            'brand_label' => $posted['brand_label'],
            'attributes' => $posted['attributes'],
        ];
    }

    /**
     * @return array{
     *     description: string,
     *     price: string,
     *     quantity: string,
     *     intake_on: string,
     *     expires_on: string,
     *     placement: string,
     *     brand_id: string,
     *     brand_label: string,
     *     attributes: array<string, array{id: string, label: string}>
     * }
     */
    private function posted(): array
    {
        return [
            'description' => $this->app->request()->field('description'),
            'price' => $this->app->request()->field('price'),
            'quantity' => $this->app->request()->field('quantity'),
            'intake_on' => $this->app->request()->field('intake_on'),
            'expires_on' => $this->app->request()->field('expires_on'),
            'placement' => $this->app->request()->field('placement'),
            'brand_id' => $this->app->request()->field('brand_id'),
            'brand_label' => $this->app->request()->field('brand_label'),
            'attributes' => $this->forms->postedAttributes(
                fn (string $name): string => $this->app->request()->field($name),
            ),
        ];
    }

    private function printId(string $vendorId): string
    {
        $id = $this->app->request()->query('print');
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1 || !$this->account->owns($vendorId, $id)) {
            return '';
        }

        return strtolower($id);
    }

    private function defaultPlacement(): string
    {
        $choices = $this->placement->choices();
        if (count($choices) === 1) {
            return $choices[0];
        }
        if (in_array('consigned', $choices, true)) {
            return 'consigned';
        }

        return $choices[0] ?? '';
    }

    private function defaultExpire(string $intake): string
    {
        $days = $this->catalog->floorDayCount();
        if ($days === null) {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $intake);
        if ($date === false) {
            return '';
        }

        return $date->modify('+' . $days . ' days')->format('Y-m-d');
    }

    private function listPath(string $slug): string
    {
        return $this->preview->url('/' . $slug . '/vendor/inventory');
    }
}
