<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

use DateTimeImmutable;
use DateTimeZone;
use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\LedgerStore;
use Psr\Log\LoggerInterface;
use Throwable;

final class BackupController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly BackupStore $backups,
        private readonly TenantBackup $archives,
        private readonly LedgerStore $ledger,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/backup', ['POST'])]
    public function download(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $return = $this->app->request()->field('return') === 'sign-out' ? 'sign-out' : 'book';
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

    private function showFailure(string $slug, string $return, string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $headline = BackupHeadline::text(
            $this->backups->latest($tenant->id),
            new DateTimeImmutable('now', new DateTimeZone('UTC'))
        );
        if ($return === 'sign-out') {
            $this->app->show('Feature/Session/sign-out', [
                'title' => 'Sign out',
                'displayName' => $tenant->displayName,
                'slug' => $tenant->slug,
                'backupHeadline' => $headline,
                'error' => $error,
            ]);
            return;
        }
        $this->app->show('Feature/Book/book', [
            'title' => 'Book',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'accounts' => $this->ledger->accounts(),
            'periods' => $this->ledger->periods(),
            'backupHeadline' => $headline,
            'error' => $error,
        ]);
    }
}
