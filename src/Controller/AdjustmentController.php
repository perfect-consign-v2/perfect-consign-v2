<?php

declare(strict_types=1);

namespace PerfectConsign\Controller;

use PerfectApp\Routing\Route;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\MallApp;

final class AdjustmentController
{
    public function __construct(private readonly MallApp $app)
    {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/adjustment', ['GET'])]
    public function form(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('adjustment', [
            'title' => 'Adjustment',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'accounts' => $this->app->books()->postableAccounts(true),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/adjustment', ['POST'])]
    public function submit(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $reference = $this->app->request()->field('source_reference');
        try {
            if ($reference === '') {
                throw new DatabaseRefusal('a source reference is required');
            }
            $journalId = $this->app->books()->post(
                'adjustment:' . $reference,
                $this->app->request()->field('posting_date'),
                'adjustment',
                $reference,
                $this->app->request()->field('description'),
                PostedLines::read($this->app->request(), 4, false)
            );
        } catch (DatabaseRefusal $refusal) {
            $this->app->show('adjustment', [
                'title' => 'Adjustment',
                'displayName' => $tenant->displayName,
                'slug' => $tenant->slug,
                'accounts' => $this->app->books()->postableAccounts(true),
                'error' => $this->app->catchRefusal($refusal),
            ]);
            return;
        } catch (\InvalidArgumentException $error) {
            $this->app->show('adjustment', [
                'title' => 'Adjustment',
                'displayName' => $tenant->displayName,
                'slug' => $tenant->slug,
                'accounts' => $this->app->books()->postableAccounts(true),
                'error' => $error->getMessage(),
            ]);
            return;
        }
        $this->app->redirect('/' . $slug . '/journals/' . $journalId);
    }
}
