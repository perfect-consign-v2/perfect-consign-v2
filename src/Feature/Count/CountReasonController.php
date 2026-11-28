<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Count;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;

/**
 * Settings → Item counts (owner): the reasons a count goes down, and how long
 * a piece left behind waits before the store may keep it.
 */
final class CountReasonController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly CountReasons $reasons,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/settings/counts', ['GET'])]
    public function show(string $slug): void
    {
        $this->page($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/settings/counts/reasons', ['POST'])]
    public function add(string $slug): void
    {
        $this->app->checkCsrf();
        $request = $this->app->request();
        $this->attempt($slug, 'Reason added.', function () use ($request): void {
            $this->reasons->add($request->field('label'), $request->field('kind'), $request->field('who'));
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/settings/counts/reasons/([0-9a-fA-F-]{36})', ['POST'])]
    public function save(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $request = $this->app->request();
        $this->attempt($slug, 'Reason saved.', function () use ($id, $request): void {
            $this->reasons->save($id, $request->field('label'), $request->field('kind'), $request->field('who'));
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/settings/counts/reasons/([0-9a-fA-F-]{36})/active', ['POST'])]
    public function active(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $on = $this->app->request()->field('active') === '1';
        $this->attempt($slug, $on ? 'Reason switched on.' : 'Reason switched off.', function () use ($id, $on): void {
            $this->reasons->setActive($id, $on);
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/settings/counts/reasons/([0-9a-fA-F-]{36})/delete', ['POST'])]
    public function remove(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->attempt($slug, 'Reason deleted.', function () use ($id): void {
            $this->reasons->remove($id);
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/settings/counts/wait', ['POST'])]
    public function wait(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $this->attempt($slug, 'Saved.', function () use ($tenant): void {
            $this->reasons->saveWaitDays($this->app->request()->field('abandon_wait_days'), $tenant->id);
        });
    }

    private function attempt(string $slug, string $notice, callable $work): void
    {
        $this->app->open($slug, true);
        try {
            $work();
        } catch (DatabaseRefusal | \InvalidArgumentException $error) {
            $this->page($slug, $this->app->catchRefusal($error));

            return;
        }
        $this->app->redirect('/' . $slug . '/settings/counts', $notice);
    }

    private function page(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Count/counts', [
            'title' => 'Item counts',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'settingsPart' => 'counts',
            'reasons' => $this->reasons->all(),
            'waitDays' => $this->reasons->waitDays(),
            'error' => $error,
        ]);
    }
}
