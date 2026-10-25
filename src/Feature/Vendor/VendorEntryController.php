<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectApp\Routing\Route;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\MallApp;

final class VendorEntryController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly Session $session,
        private readonly VendorEntry $entry,
        private readonly VendorPortal $portal,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/consignor/enter', ['GET'])]
    public function form(string $slug): void
    {
        $tenant = $this->app->open($slug, false);
        $this->app->show('Feature/Vendor/enter', [
            'title' => 'Consignor sign in',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'error' => null,
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/consignor/enter', ['POST'])]
    public function submit(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, false);
        $this->entry->request($tenant, $this->app->request()->field('email'), $this->app->request()->origin);
        $this->app->redirect('/' . $slug . '/consignor/enter', VendorEntry::SENT);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/consignor/enter/([0-9a-f]{64})', ['GET'])]
    public function redeem(string $slug, string $token): void
    {
        $tenant = $this->app->open($slug, false);
        $vendorId = $this->entry->open($tenant, hash('sha256', $token));
        if ($vendorId === '') {
            $this->app->redirect('/' . $slug . '/consignor/enter', 'That link has expired.');
        }
        $this->session->set($this->key($slug), $vendorId);
        $this->app->redirect('/' . $slug . '/consignor');
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/consignor', ['GET'])]
    public function home(string $slug): void
    {
        $tenant = $this->app->open($slug, false);
        $vendorId = $this->vendor($slug);
        $this->app->show('Feature/Vendor/consignor', [
            'title' => 'Your account',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'page' => $this->portal->page($tenant, $vendorId),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/consignor/preference', ['POST'])]
    public function preference(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, false);
        $this->portal->prefer($tenant, $this->vendor($slug), $this->app->request()->field('payout_mode'));
        $this->app->redirect('/' . $slug . '/consignor', 'Payout preference saved.');
    }

    private function vendor(string $slug): string
    {
        $vendorId = $this->session->get($this->key($slug));
        if (!is_string($vendorId) || preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1) {
            $this->app->redirect('/' . $slug . '/consignor/enter');
        }

        return $vendorId;
    }

    private function key(string $slug): string
    {
        return 'consignor:' . $slug;
    }
}
