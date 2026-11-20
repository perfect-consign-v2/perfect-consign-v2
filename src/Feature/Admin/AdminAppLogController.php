<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Shared\Http\ProbeBanStore;
use Psr\Log\LoggerInterface;

final class AdminAppLogController
{
    public function __construct(
        private readonly AdminApp $app,
        private readonly AppLogFile $logFile,
        private readonly AdminAudit $audit,
        private readonly LoggerInterface $logger,
        private readonly ProbeBanStore $bans,
    ) {
    }

    #[AdminRoute('/log', ['GET'])]
    public function index(): void
    {
        $this->app->requireAdmin();
        $this->app->show('Feature/Admin/app-log', [
            'title' => 'App log',
            'log' => $this->logFile->snapshot(),
            'tailKb' => (int) (AppLogFile::TAIL_BYTES / 1000),
            'bans' => $this->bans->listBans(),
        ]);
    }

    #[AdminRoute('/log/lift', ['POST'])]
    public function lift(): void
    {
        $this->app->checkCsrf();
        $admin = $this->app->requireAdmin();
        $address = $this->app->request()->field('address');
        if (preg_match('/\A[A-Za-z0-9:.]{1,64}\z/', $address) !== 1 || !$this->bans->lift($address)) {
            $this->app->redirect($this->app->url('/log'), null, 'That address is not banned.');
        }
        $this->audit->record(
            $admin['id'],
            $admin['email'],
            'probe_ban_lift',
            'ok',
            null,
            null,
            ['address' => $address],
        );
        $this->app->redirect($this->app->url('/log'), 'Lifted the ban for ' . $address . '.');
    }

    #[AdminRoute('/log/clear', ['POST'])]
    public function clear(): void
    {
        $this->app->checkCsrf();
        $admin = $this->app->requireAdmin();
        $bytes = $this->logFile->byteCount();
        $this->logFile->clear();
        $this->logger->info('application log cleared', ['bytes_removed' => $bytes]);
        $this->audit->record(
            $admin['id'],
            $admin['email'],
            'app.log_clear',
            'ok',
            null,
            null,
            ['bytes_removed' => $bytes],
        );
        $this->app->redirect(
            $this->app->url('/log'),
            'Cleared application log (' . (string) $bytes . ' byte(s) removed).',
        );
    }
}
