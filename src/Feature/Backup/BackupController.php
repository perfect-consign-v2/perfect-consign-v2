<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

use DateTimeImmutable;
use DateTimeZone;
use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallController;
use Psr\Log\LoggerInterface;
use Throwable;

final class BackupController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly BackupStore $backups,
        private readonly TenantBackup $archives,
        private readonly MallController $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/backup', ['POST'])]
    public function download(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $return = $this->returnPath($this->app->request()->field('return'));
        $archive = null;
        try {
            $archive = $this->archives->create(BackupConnection::stallBackup(), $slug);
            if (!$this->archives->send($archive)) {
                $this->logger->info('owner backup download closed early', ['slug' => $slug]);
                return;
            }
            $this->backups->record($tenant->id, $archive->id, $archive->byteSize, $archive->sha256);
        } catch (BackupException $error) {
            if (!headers_sent()) {
                $this->showFailure($slug, $return, $error->getMessage());
                return;
            }
            $this->logger->error('owner backup failed after the download started', [
                'slug' => $slug,
                'message' => $error->getMessage(),
            ]);
        } catch (Throwable $error) {
            $this->logger->error('owner backup failed', ['slug' => $slug, 'message' => $error->getMessage()]);
            if (!headers_sent()) {
                $this->showFailure($slug, $return, 'The backup could not be taken.');
            }
        } finally {
            if ($archive !== null) {
                $this->archives->delete($archive);
            }
        }
    }

    private function returnPath(string $return): string
    {
        if ($return === 'sign-out') {
            return 'sign-out';
        }
        if ($return === 'settings/backup' || $return === 'settings') {
            return 'settings/backup';
        }

        return 'settings/backup';
    }

    private function showFailure(string $slug, string $return, string $error): void
    {
        $tenant = $this->app->open($slug, true);
        if ($return === 'sign-out') {
            $headline = BackupHeadline::text(
                $this->backups->latest($tenant->id),
                new DateTimeImmutable('now', new DateTimeZone('UTC'))
            );
            $this->app->show('Feature/Session/sign-out', [
                'title' => 'Sign out',
                'displayName' => $tenant->displayName,
                'slug' => $tenant->slug,
                'backupHeadline' => $headline,
                'error' => $error,
            ]);
            return;
        }
        $this->settings->showError($slug, $error, 'backup');
    }
}
