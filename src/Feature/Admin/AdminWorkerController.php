<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Shared\Jobs\JobAttention;
use PerfectConsign\Shared\Jobs\WorkerRuns;

final class AdminWorkerController
{
    public function __construct(
        private readonly AdminApp $app,
        private readonly WorkerRuns $runs,
        private readonly JobAttention $jobs,
    ) {
    }

    #[AdminRoute('/worker', ['GET'])]
    public function index(): void
    {
        $this->app->requireAdmin();
        $status = $this->runs->status();
        $status['site_smtp'] = $this->jobs->siteReady();
        $status['platform_gmail'] = $this->jobs->gmailReady();
        $this->app->show('Feature/Admin/worker', [
            'title' => 'Mail worker',
            'status' => $status,
            'queue' => $this->jobs->open(),
            'givenUp' => $this->jobs->givenUp(),
            'runs' => $this->runs->recent(100),
        ]);
    }

    #[AdminRoute('/worker/clear', ['POST'])]
    public function clear(): void
    {
        $this->app->checkCsrf();
        $this->app->requireAdmin();
        $deleted = $this->runs->clear();
        $this->app->redirect($this->app->url('/worker'), 'Cleared ' . (string) $deleted . ' worker run row(s).');
    }

    #[AdminRoute('/worker/prune', ['POST'])]
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
            $this->app->url('/worker'),
            'Pruned ' . (string) $deleted . ' worker run row(s) older than ' . (string) $days . ' day(s).',
        );
    }
}
