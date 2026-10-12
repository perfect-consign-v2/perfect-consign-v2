<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Dev;

use PerfectApp\Routing\Route;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use Psr\Log\LoggerInterface;
use Throwable;

final class DevResetController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly Actor $actor,
        private readonly Session $session,
        private readonly LoggerInterface $logger,
        private readonly DevTenantReset $reset,
        private readonly MallTenantReset $mall,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reset-test-data', ['POST'])]
    public function reset(string $slug): void
    {
        $this->app->checkCsrf();
        if (!$this->allowed($slug)) {
            $this->app->notFound(MallApp::MISS_HIDDEN);
        }
        try {
            $this->reload($slug);
        } catch (Throwable $error) {
            $this->logger->error('test data reset failed', [
                'slug' => $slug,
                'message' => $error->getMessage(),
            ]);
            $this->app->redirect('/' . $slug . '/dashboard', null, 'Test data was not reset.');
        }
        $this->clearMall($slug);
        $this->app->redirect('/' . $slug . '/dashboard', 'Test data reset.');
    }

    private function allowed(string $slug): bool
    {
        if ($this->actor->role() !== 'owner' || $this->actor->slug() !== $slug) {
            return false;
        }

        return $slug === DevTenantReset::SLUG || $slug === MallTenantReset::SLUG;
    }

    private function reload(string $slug): void
    {
        if ($slug === MallTenantReset::SLUG) {
            $this->mall->load();

            return;
        }
        $this->reset->load();
    }

    private function clearMall(string $slug): void
    {
        foreach (['register:', 'register_name:', 'sale:', 'sale_tendered:', 'close_prior:'] as $prefix) {
            $this->session->delete($prefix . $slug);
        }
    }
}
