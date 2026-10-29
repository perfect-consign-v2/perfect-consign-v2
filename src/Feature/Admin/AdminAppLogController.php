<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectApp\Routing\Route;
use Psr\Log\LoggerInterface;

final class AdminAppLogController
{
    public function __construct(
        private readonly AdminApp $app,
        private readonly AppLogFile $logFile,
        private readonly AdminAudit $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/admin/log', ['GET'])]
    public function index(): void
    {
        $this->app->requireAdmin();
        $this->app->show('Feature/Admin/app-log', [
            'title' => 'App log',
            'log' => $this->logFile->snapshot(),
            'tailKb' => (int) (AppLogFile::TAIL_BYTES / 1000),
        ]);
    }

    #[Route('/admin/log/clear', ['POST'])]
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
            '/admin/log',
            'Cleared application log (' . (string) $bytes . ' byte(s) removed).',
        );
    }
}
