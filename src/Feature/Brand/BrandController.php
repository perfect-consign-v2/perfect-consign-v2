<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Brand;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class BrandController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly BrandStore $brands,
        private readonly MallSettings $mall,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/brands', ['GET'])]
    public function show(string $slug): void
    {
        $this->list($slug, null);
    }

    private function list(string $slug, ?string $error, ?string $modal = null, string $draft = ''): void
    {
        $tenant = $this->requireBrand($slug);
        $query = trim($this->app->request()->query('q'));
        $brands = $this->withUse($this->brands->brands($query));
        if ($modal === null) {
            $modal = '';
            if ($this->app->request()->query('new') === '1') {
                $modal = 'new';
            }
            $rename = $this->app->request()->query('rename');
            if (preg_match('/^[0-9a-fA-F-]{36}$/', $rename) === 1) {
                $modal = $rename;
            }
        }
        if ($modal !== '' && $modal !== 'new' && $draft === '') {
            $draft = $this->draftName($brands, $modal);
        }
        $this->app->show('Feature/Brand/brands', [
            'title' => 'Brands',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'brands' => $brands,
            'query' => $query,
            'total' => $query === '' ? count($brands) : $this->brands->count(),
            'confirm' => $this->confirming($brands, $this->app->request()->query('confirm')),
            'modal' => $modal,
            'draft' => $draft,
            'error' => $error,
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/brands/new', ['GET'])]
    public function create(string $slug): void
    {
        $this->requireBrand($slug);
        $this->app->redirect('/' . $slug . '/brands?new=1');
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
        $this->requireBrand($slug);
        if ($this->brands->find($id) === null) {
            $this->app->notFound();
        }
        $this->app->redirect('/' . $slug . '/brands?rename=' . $id);
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
        $this->requireBrand($slug);
        $error = null;
        try {
            $this->brands->remove($id);
        } catch (DatabaseRefusal $refusal) {
            $error = $this->brandError($this->app->catchRefusal($refusal));
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/brands', 'Brand deleted.');
        }
        $this->list($slug, $error);
    }

    private function change(string $slug, ?string $id, callable $action): void
    {
        $this->requireBrand($slug);
        $error = null;
        try {
            $action();
        } catch (DatabaseRefusal $refusal) {
            $error = $this->brandError($this->app->catchRefusal($refusal));
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/brands', $id === null ? 'Brand created.' : 'Brand saved.');
        }
        $this->list($slug, $error, $id ?? 'new', $this->app->request()->field('name'));
    }

    /**
     * @param list<array<string, mixed>> $brands
     */
    private function draftName(array $brands, string $id): string
    {
        foreach ($brands as $brand) {
            if (($brand['id'] ?? null) === $id && is_string($brand['name'] ?? null)) {
                return $brand['name'];
            }
        }
        $brand = $this->brands->find($id);

        return is_array($brand) && is_string($brand['name'] ?? null) ? $brand['name'] : '';
    }

    /**
     * @param list<array<string, mixed>> $brands
     * @return list<array<string, mixed>>
     */
    private function withUse(array $brands): array
    {
        $held = $this->brands->referenced();
        $marked = [];
        foreach ($brands as $brand) {
            $id = $brand['id'] ?? null;
            $brand['deletable'] = is_string($id) && !isset($held[$id]);
            $marked[] = $brand;
        }

        return $marked;
    }

    /**
     * @param list<array<string, mixed>> $brands
     */
    private function confirming(array $brands, string $confirm): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $confirm) !== 1) {
            return '';
        }
        $needle = strtolower($confirm);
        foreach ($brands as $brand) {
            $id = $brand['id'] ?? null;
            if (is_string($id) && strtolower($id) === $needle && ($brand['deletable'] ?? false) === true) {
                return $id;
            }
        }

        return '';
    }

    private function requireBrand(string $slug): TenantRecord
    {
        $tenant = $this->app->open($slug, true);
        if (!$this->mall->enabled('use_brand')) {
            $this->app->notFound();
        }

        return $tenant;
    }

    private function brandError(string $message): string
    {
        if (str_contains($message, 'brands_name_key')) {
            return 'That name is already used.';
        }
        if (str_contains($message, 'brands_name_chk')) {
            return 'Enter a brand name.';
        }
        if (str_contains($message, 'foreign key constraint') || str_contains($message, 'is still referenced')) {
            return 'That brand is in use.';
        }

        return $message;
    }
}
