<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;

final class ReportSheetsController
{
    private readonly ReportAccess $access;

    public function __construct(
        private readonly MallApp $app,
        Actor $actor,
        private readonly MallSettings $mall,
        private readonly ReportFetch $reports,
    ) {
        $this->access = new ReportAccess($app, $actor);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/rent', ['GET'])]
    public function rent(string $slug): void
    {
        $this->present($slug, fn (): ReportPage => $this->reports->rent($this->app->request(), $this->mall->today()));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/stored-value', ['GET'])]
    public function storedValue(string $slug): void
    {
        $this->present($slug, fn (): ReportPage => $this->reports->stored($this->app->request(), $this->mall->today()));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/expiring', ['GET'])]
    public function expiring(string $slug): void
    {
        $this->present($slug, fn (): ReportPage => $this->reports->expiring($this->app->request(), $this->mall->today()));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/donated', ['GET'])]
    public function donated(string $slug): void
    {
        $this->present($slug, fn (): ReportPage => $this->reports->donated($this->app->request(), $this->mall->today()));
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reports/sell-through', ['GET'])]
    public function sellThrough(string $slug): void
    {
        $this->present($slug, fn (): ReportPage => $this->reports->sellThrough($this->app->request(), $this->mall->today()));
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
}
