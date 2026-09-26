<?php

declare(strict_types=1);

namespace PerfectConsign\Controller;

use PerfectApp\Routing\Route;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\MallApp;

final class ExportController
{
    public function __construct(private readonly MallApp $app)
    {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/export', ['GET'])]
    public function form(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('export', [
            'title' => 'Export',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/export', ['POST'])]
    public function download(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $from = $this->app->request()->field('from');
        $to = $this->app->request()->field('to');
        if ($from !== '' && $to !== '' && $to < $from) {
            $to = $from;
        }
        $system = $this->app->request()->field('system');
        $taxRate = $this->app->request()->field('tax_rate');
        if ($taxRate === '') {
            $taxRate = 'Tax Exempt';
        }
        try {
            $statement = $this->app->books()->exportCsv($system, $from, $to, $taxRate);
        } catch (DatabaseRefusal $refusal) {
            $this->app->show('export', [
                'title' => 'Export',
                'displayName' => $tenant->displayName,
                'slug' => $tenant->slug,
                'error' => $this->app->catchRefusal($refusal),
                'from' => $from,
                'to' => $to,
            ]);
            return;
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $system . '-journals.csv"');
        echo $statement;
    }
}
