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
        $this->attempt($slug, $list, ucfirst($list->singular) . ' deleted.', function () use ($list, $id): void {
            $this->lists->remove($list, $id);
        }, null, '', $this->landing());
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(colors|categories|sizes|conditions|departments)/([0-9a-fA-F-]{36})/active', ['POST'])]
    public function active(string $slug, string $catalog, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $list = $this->requireList($catalog);
        $flag = $this->app->request()->field('active');
        if ($flag !== '0' && $flag !== '1') {
            $this->page($slug, $list, 'Choose active or inactive.');

            return;
        }
        $word = ucfirst($list->singular);
        $saved = $flag === '1' ? $word . ' is active.' : $word . ' is inactive.';
        $landing = $flag === '1' ? '' : '?status=inactive';
        $this->attempt($slug, $list, $saved, function () use ($list, $id, $flag): void {
            $this->lists->setActive($list, $id, $flag === '1');
        }, null, '', $landing);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/(colors|categories|sizes|conditions|departments)/([0-9a-fA-F-]{36})/merge', ['POST'])]
    public function merge(string $slug, string $catalog, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $list = $this->requireList($catalog);
        $target = $this->app->request()->field('target');
        $this->attempt($slug, $list, ucfirst($list->singular) . ' merged.', function () use ($list, $id, $target): void {
            $this->lists->merge($list, $id, $target);
        });
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
        $saved = $id === null ? ucfirst($list->singular) . ' created.' : ucfirst($list->singular) . ' saved.';
        $this->attempt(
            $slug,
            $list,
            $saved,
            $action,
            $id ?? 'new',
            $this->app->request()->field('name'),
            $id === null ? '' : $this->landing(),
        );
    }

    private function attempt(
        string $slug,
        NameList $list,
        string $saved,
        callable $action,
        ?string $modal = null,
        string $draft = '',
        string $landing = '',
    ): void {
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
            $this->app->redirect('/' . $slug . '/' . $list->path . $landing, $saved);
        }
        $this->page($slug, $list, $error, $modal, $draft);
    }

    private function landing(): string
    {
        return $this->app->request()->field('status') === 'inactive' ? '?status=inactive' : '';
    }

    private function page(string $slug, NameList $list, ?string $error, ?string $modal = null, string $draft = ''): void
    {
        $tenant = $this->app->open($slug, true);
        $query = trim($this->app->request()->query('q'));
        $standing = NameStanding::chosen($this->app->request()->query('status'));
        $held = $this->lists->referenced($list);
        $counts = $this->lists->itemCounts($list);
        $matched = NameBoard::mark($this->lists->rows($list, $query), $held, $counts);
        $split = NameStanding::split($matched, $standing);
        $rows = $split['rows'];
        $all = $query === '' ? $matched : NameBoard::mark($this->lists->rows($list, ''), $held, $counts);
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
            $draft = $this->draftName($list, $rows, $modal);
        }
        $this->app->show('Feature/Catalog/names', [
            'title' => $list->plural,
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'list' => $list,
            'rows' => $rows,
            'query' => $query,
            'standing' => $standing,
            'activeCount' => $split['active'],
            'inactiveCount' => $split['inactive'],
            'total' => $this->lists->count($list),
            'confirm' => NameBoard::confirming($rows, $this->app->request()->query('confirm')),
            'merge' => $merge['id'],
            'mergeSentence' => $merge['sentence'],
            'mergeChoices' => $merge['choices'],
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

    private function listError(NameList $list, string $message): string
    {
        unset($list);

        return $message;
    }
}
