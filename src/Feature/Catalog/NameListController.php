<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Catalog;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;

final class NameListController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly NameListStore $lists,
        private readonly MallSettings $mall,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(colors|categories|sizes|conditions|departments)', ['GET'])]
    public function show(string $slug, string $catalog): void
    {
        $this->app->open($slug, true);
        $this->page($slug, $this->requireList($catalog), null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(colors|categories|sizes|conditions|departments)/new', ['GET'])]
    public function create(string $slug, string $catalog): void
    {
        $this->app->open($slug, true);
        $list = $this->requireList($catalog);
        $this->app->redirect('/' . $slug . '/' . $list->path . '?new=1');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(colors|categories|sizes|conditions|departments)', ['POST'])]
    public function add(string $slug, string $catalog): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $list = $this->requireList($catalog);
        $this->change($slug, $list, null, function () use ($list): void {
            $this->lists->add($list, $this->app->request()->field('name'));
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(colors|categories|sizes|conditions|departments)/([0-9a-fA-F-]{36})/edit', ['GET'])]
    public function edit(string $slug, string $catalog, string $id): void
    {
        $this->app->open($slug, true);
        $list = $this->requireList($catalog);
        if ($this->lists->find($list, $id) === null) {
            $this->app->notFound();
        }
        $this->app->redirect('/' . $slug . '/' . $list->path . '?rename=' . $id);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(colors|categories|sizes|conditions|departments)/([0-9a-fA-F-]{36})', ['POST'])]
    public function rename(string $slug, string $catalog, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $list = $this->requireList($catalog);
        $this->change($slug, $list, $id, function () use ($list, $id): void {
            $this->lists->rename($list, $id, $this->app->request()->field('name'));
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(colors|categories|sizes|conditions|departments)/([0-9a-fA-F-]{36})/delete', ['POST'])]
    public function remove(string $slug, string $catalog, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $list = $this->requireList($catalog);
        $error = null;
        try {
            $this->lists->remove($list, $id);
        } catch (DatabaseRefusal $refusal) {
            $error = $this->listError($list, $this->app->catchRefusal($refusal));
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/' . $list->path, ucfirst($list->singular) . ' deleted.');
        }
        $this->page($slug, $list, $error);
    }

    private function requireList(string $catalog): NameList
    {
        $list = NameList::fromPath($catalog);
        if ($list === null || !$this->mall->enabled($list->flag)) {
            $this->app->notFound();
        }

        return $list;
    }

    private function change(string $slug, NameList $list, ?string $id, callable $action): void
    {
        $this->app->open($slug, true);
        $error = null;
        try {
            $action();
        } catch (DatabaseRefusal $refusal) {
            $error = $this->listError($list, $this->app->catchRefusal($refusal));
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        if ($error === null) {
            $this->app->redirect(
                '/' . $slug . '/' . $list->path,
                $id === null ? ucfirst($list->singular) . ' created.' : ucfirst($list->singular) . ' saved.'
            );
        }
        $this->page($slug, $list, $error, $id ?? 'new', $this->app->request()->field('name'));
    }

    private function page(string $slug, NameList $list, ?string $error, ?string $modal = null, string $draft = ''): void
    {
        $tenant = $this->app->open($slug, true);
        $query = trim($this->app->request()->query('q'));
        $rows = $this->withUse($list, $this->lists->rows($list, $query));
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
            $draft = $this->draftName($list, $rows, $modal);
        }
        $this->app->show('Feature/Catalog/names', [
            'title' => $list->plural,
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'list' => $list,
            'rows' => $rows,
            'query' => $query,
            'total' => $query === '' ? count($rows) : $this->lists->count($list),
            'confirm' => $this->confirming($rows, $this->app->request()->query('confirm')),
            'modal' => $modal,
            'draft' => $draft,
            'error' => $error,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function draftName(NameList $list, array $rows, string $id): string
    {
        foreach ($rows as $row) {
            if (($row['id'] ?? null) === $id && is_string($row['name'] ?? null)) {
                return $row['name'];
            }
        }
        $row = $this->lists->find($list, $id);

        return is_array($row) && is_string($row['name'] ?? null) ? $row['name'] : '';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withUse(NameList $list, array $rows): array
    {
        $held = $this->lists->referenced($list);
        $marked = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $row['deletable'] = is_string($id) && !isset($held[$id]);
            $marked[] = $row;
        }

        return $marked;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function confirming(array $rows, string $confirm): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $confirm) !== 1) {
            return '';
        }
        $needle = strtolower($confirm);
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (is_string($id) && strtolower($id) === $needle && ($row['deletable'] ?? false) === true) {
                return $id;
            }
        }

        return '';
    }

    private function listError(NameList $list, string $message): string
    {
        if (str_contains($message, $list->table . '_name_key')) {
            return 'That name is already used.';
        }
        if (str_contains($message, $list->table . '_name_chk')) {
            return 'Enter a ' . $list->singular . ' name.';
        }
        if (str_contains($message, 'foreign key constraint') || str_contains($message, 'is still referenced')) {
            return 'That ' . $list->singular . ' is in use.';
        }

        return $message;
    }
}
