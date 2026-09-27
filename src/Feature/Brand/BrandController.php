<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Brand;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;

final class BrandController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly BrandStore $brands,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/brands', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Brand/brands', [
            'title' => 'Brands',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'brands' => $this->brands->brands(),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/brands/new', ['GET'])]
    public function create(string $slug): void
    {
        $this->form($slug, null, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/brands', ['POST'])]
    public function add(string $slug): void
    {
        $this->app->checkCsrf();
        $this->change($slug, null, function (): void {
            $this->brands->add($this->app->request()->field('name'));
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/brands/([0-9a-fA-F-]{36})/edit', ['GET'])]
    public function edit(string $slug, string $id): void
    {
        $this->app->open($slug, true);
        $brand = $this->brands->find($id);
        if ($brand === null) {
            $this->app->notFound();
        }
        $this->form($slug, $brand, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/brands/([0-9a-fA-F-]{36})', ['POST'])]
    public function rename(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->change($slug, $id, function () use ($id): void {
            $this->brands->rename($id, $this->app->request()->field('name'));
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/brands/([0-9a-fA-F-]{36})/delete', ['POST'])]
    public function remove(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $error = null;
        try {
            $this->brands->remove($id);
        } catch (DatabaseRefusal $refusal) {
            $error = $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/brands', 'Brand deleted.');
        }
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Brand/brands', [
            'title' => 'Brands',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'brands' => $this->brands->brands(),
            'error' => $error,
        ]);
    }

    private function change(string $slug, ?string $id, callable $action): void
    {
        $this->app->open($slug, true);
        $error = null;
        try {
            $action();
        } catch (DatabaseRefusal $refusal) {
            $error = $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/brands', $id === null ? 'Brand created.' : 'Brand saved.');
        }
        $brand = [
            'name' => $this->app->request()->field('name'),
        ];
        if ($id !== null) {
            $brand['id'] = $id;
        }
        $this->form($slug, $brand, $error);
    }

    /**
     * @param array<string, mixed>|null $brand
     */
    private function form(string $slug, ?array $brand, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Brand/brand-form', [
            'title' => $brand !== null && isset($brand['id']) ? 'Edit brand' : 'New brand',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'brand' => $brand,
            'error' => $error,
        ]);
    }
}
