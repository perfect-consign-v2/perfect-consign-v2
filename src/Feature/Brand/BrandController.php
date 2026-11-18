<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Brand;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Catalog\NameBoard;
use PerfectConsign\Feature\Catalog\NameStanding;
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
        $standing = NameStanding::chosen($this->app->request()->query('status'));
        $held = $this->brands->referenced();
        $counts = $this->brands->itemCounts();
        $matched = NameBoard::mark($this->brands->brands($query), $held, $counts);
        $split = NameStanding::split($matched, $standing);
        $brands = $split['rows'];
        $all = $query === '' ? $matched : NameBoard::mark($this->brands->brands(''), $held, $counts);
        $merge = NameBoard::merge($all, $this->app->request()->query('merge'));
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
            'standing' => $standing,
            'activeCount' => $split['active'],
            'inactiveCount' => $split['inactive'],
            'total' => $this->brands->count(),
            'confirm' => NameBoard::confirming($brands, $this->app->request()->query('confirm')),
            'merge' => $merge['id'],
            'mergeSentence' => $merge['sentence'],
            'mergeChoices' => $merge['choices'],
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
        $this->attempt($slug, 'Brand deleted.', function () use ($id): void {
            $this->brands->remove($id);
        }, null, '', $this->landing());
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/brands/([0-9a-fA-F-]{36})/active', ['POST'])]
    public function active(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $flag = $this->app->request()->field('active');
        if ($flag !== '0' && $flag !== '1') {
            $this->requireBrand($slug);
            $this->list($slug, 'Choose active or inactive.');

            return;
        }
        $saved = $flag === '1' ? 'Brand is active.' : 'Brand is inactive.';
        $landing = $flag === '1' ? '' : '?status=inactive';
        $this->attempt($slug, $saved, function () use ($id, $flag): void {
            $this->brands->setActive($id, $flag === '1');
        }, null, '', $landing);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/brands/([0-9a-fA-F-]{36})/merge', ['POST'])]
    public function merge(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $target = $this->app->request()->field('target');
        $this->attempt($slug, 'Brand merged.', function () use ($id, $target): void {
            $this->brands->merge($id, $target);
        });
    }

    private function change(string $slug, ?string $id, callable $action): void
    {
        $saved = $id === null ? 'Brand created.' : 'Brand saved.';
        $this->attempt($slug, $saved, $action, $id ?? 'new', $this->app->request()->field('name'), $id === null ? '' : $this->landing());
    }

    private function attempt(string $slug, string $saved, callable $action, ?string $modal = null, string $draft = '', string $landing = ''): void
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
            $this->app->redirect('/' . $slug . '/brands' . $landing, $saved);
        }
        $this->list($slug, $error, $modal, $draft);
    }

    private function landing(): string
    {
        return $this->app->request()->field('status') === 'inactive' ? '?status=inactive' : '';
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
        return $message;
    }
}
