<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class ReportCsvController
{
    private readonly ReportAccess $access;

    public function __construct(
        private readonly MallApp $app,
        Actor $actor,
        private readonly MallSettings $mall,
        private readonly ReportFetch $reports,
        private readonly SalesTaxReport $tax,
    ) {
        $this->access = new ReportAccess($app, $actor);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/sales-tax.csv', ['GET'])]
    public function salesTaxCsv(string $slug): void
    {
        $this->deliver($slug, fn (): ReportPage => $this->tax->page($this->app->request(), $this->mall->today()));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/sales.csv', ['GET'])]
    public function salesCsv(string $slug): void
    {
        $this->deliver($slug, fn (): ReportPage => $this->reports->sales($this->app->request(), $this->mall->today()));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/rent.csv', ['GET'])]
    public function rentCsv(string $slug): void
    {
        $this->deliver($slug, fn (): ReportPage => $this->reports->rent($this->app->request(), $this->mall->today()));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/payouts.csv', ['GET'])]
    public function payoutsCsv(string $slug): void
    {
        $this->deliver($slug, fn (): ReportPage => $this->reports->payouts($this->app->request(), $this->mall->today()));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/stored-value.csv', ['GET'])]
    public function storedValueCsv(string $slug): void
    {
        $this->deliver($slug, fn (): ReportPage => $this->reports->stored($this->app->request(), $this->mall->today()));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/expiring.csv', ['GET'])]
    public function expiringCsv(string $slug): void
    {
        $this->deliver($slug, fn (): ReportPage => $this->reports->expiring($this->app->request(), $this->mall->today()));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/donated.csv', ['GET'])]
    public function donatedCsv(string $slug): void
    {
        $this->deliver($slug, fn (): ReportPage => $this->reports->donated($this->app->request(), $this->mall->today()));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/sell-through.csv', ['GET'])]
    public function sellThroughCsv(string $slug): void
    {
        $this->deliver($slug, fn (): ReportPage => $this->reports->sellThrough($this->app->request(), $this->mall->today()));
    }

    /**
     * @param callable(): ReportPage $load
     */
    private function deliver(string $slug, callable $load): void
    {
        $tenant = $this->access->open($slug);
        $page = $load();
        if ($page->csv === null) {
            $this->show($tenant, $page);

            return;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $page->filename . '"');
        header('Cache-Control: no-store');
        echo $page->csv;
        throw new ResponseSent('csv');
    }

    private function show(TenantRecord $tenant, ReportPage $page): void
    {
        $this->app->show($page->template, $page->data + [
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
        ]);
    }
}
