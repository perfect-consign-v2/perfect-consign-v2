<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectApp\Routing\Route;
use PerfectConsign\Shared\Jobs\WorkerRuns;

final class AdminWorkerController
{
    public function __construct(
        private readonly AdminApp $app,
        private readonly WorkerRuns $runs,
    ) {
    }

    #[Route('/admin/worker', ['GET'])]
    public function index(): void
    {
        $this->app->requireAdmin();
        $this->app->show('Feature/Admin/worker', [
            'title' => 'Mail worker',
            'status' => $this->runs->status(),
            'runs' => $this->runs->recent(100),
        ]);
    }

    #[Route('/admin/worker/clear', ['POST'])]
    public function clear(): void
    {
        $this->app->checkCsrf();
        $this->app->requireAdmin();
        $deleted = $this->runs->clear();
        $this->app->redirect('/admin/worker', 'Cleared ' . (string) $deleted . ' worker run row(s).');
    }

    #[Route('/admin/worker/prune', ['POST'])]
    public function prune(): void
    {
        $this->app->checkCsrf();
        $this->app->requireAdmin();
        $days = (int) $this->app->request()->field('days');
        if ($days < 1) {
            $days = 30;
        }
        if ($days > 365) {
            $days = 365;
        }
        $deleted = $this->runs->pruneOlderThanDays($days);
        $this->app->redirect(
            '/admin/worker',
            'Pruned ' . (string) $deleted . ' worker run row(s) older than ' . (string) $days . ' day(s).',
        );
    }
}
