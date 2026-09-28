<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Root;

use PerfectApp\Routing\Route;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\TenantLookup;

final class RootController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly Session $session,
        private readonly TenantLookup $tenants,
    ) {
    }

    #[Route('/', ['GET'])]
    public function show(): void
    {
        $slug = $this->signedInSlug();
        if ($slug !== null) {
            $this->app->redirect('/' . $slug . '/dashboard');
        }
        $this->page(null, '');
    }

    #[Route('/', ['POST'])]
    public function open(): void
    {
        $this->app->checkCsrf();
        $mall = strtolower($this->app->request()->field('mall'));
        $tenant = $this->tenants->find($mall);
        if ($tenant === null) {
            $this->page('No mall uses that address.', $mall);

            return;
        }
        $this->app->redirect('/' . $tenant->slug . '/login');
    }

    private function page(?string $error, string $mall): void
    {
        $this->app->show('Shared/Web/root', [
            'title' => 'Perfect Consign',
            'displayName' => '',
            'slug' => '',
            'error' => null,
            'mall' => $mall,
            'mallError' => $error,
        ]);
    }

    private function signedInSlug(): ?string
    {
        $slug = $this->session->get('owner_slug');
        if (!is_string($slug) || $slug === '' || $this->tenants->find($slug) === null) {
            return null;
        }

        return $slug;
    }
}
