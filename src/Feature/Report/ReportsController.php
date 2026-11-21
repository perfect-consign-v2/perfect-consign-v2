<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class ReportsController
{
    private readonly ReportAccess $access;

    public function __construct(
        private readonly MallApp $app,
        Actor $actor,
        private readonly MallSettings $mall,
        private readonly ReportFetch $reports,
        private readonly SalesTaxReport $tax,
        private readonly SavedReport $saved,
    ) {
        $this->access = new ReportAccess($app, $actor);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports', ['GET'])]
    public function show(string $slug): void
    {
        $tenant = $this->access->open($slug);
        $this->app->show('Feature/Report/reports', [
            'title' => 'Reports',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'saved' => $this->saved->list(),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/sales-tax', ['GET'])]
    public function salesTax(string $slug): void
    {
        $this->present($slug, fn (): ReportPage => $this->tax->page($this->app->request(), $this->mall->today()));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/sales', ['GET'])]
    public function sales(string $slug): void
    {
        $this->present($slug, fn (): ReportPage => $this->reports->sales($this->app->request(), $this->mall->today()));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/payouts', ['GET'])]
    public function payouts(string $slug): void
    {
        $this->present($slug, fn (): ReportPage => $this->reports->payouts($this->app->request(), $this->mall->today()));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/build', ['GET'])]
    public function build(string $slug): void
    {
        $this->form($this->access->open($slug), null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/build', ['POST'])]
    public function store(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->access->open($slug);
        $request = $this->app->request();
        try {
            $this->saved->save(
                $request->field('name'),
                $request->field('sheet'),
                $request->field('grouped'),
                $request->field('range_key'),
            );
        } catch (\InvalidArgumentException $error) {
            $this->form($tenant, $error->getMessage());

            return;
        } catch (DatabaseRefusal $error) {
            $this->form($tenant, $this->savedError($error));

            return;
        }
        $this->app->redirect('/' . $tenant->slug . '/reports/build', 'Report saved.');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/saved/([0-9a-fA-F-]{36})', ['GET'])]
    public function savedReport(string $slug, string $id): void
    {
        $tenant = $this->access->open($slug);
        $report = $this->saved->find($id);
        if ($report === null) {
            $this->app->notFound();
        }
        $this->app->redirect('/' . $tenant->slug . $this->saved->path($report));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/saved/([0-9a-fA-F-]{36})/delete', ['POST'])]
    public function deleteSaved(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $tenant = $this->access->open($slug);
        if ($this->saved->find($id) === null) {
            $this->app->notFound();
        }
        $this->saved->remove($id);
        $this->app->redirect('/' . $tenant->slug . '/reports/build', 'Report removed.');
    }

    /**
     * @param callable(): ReportPage $load
     */
    private function present(string $slug, callable $load): void
    {
        $tenant = $this->access->open($slug);
        $page = $load();
        $this->app->show($page->template, $page->data + [
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
        ]);
    }

    private function form(TenantRecord $tenant, ?string $error): void
    {
        $this->app->show('Feature/Report/build', [
            'title' => 'Bookmarks',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'error' => $error,
            'saved' => $this->saved->list(),
        ]);
    }

    private function savedError(DatabaseRefusal $error): string
    {
        if ($error->sqlState() === '23505') {
            return 'That name is already used.';
        }

        return $error->getMessage();
    }
}
