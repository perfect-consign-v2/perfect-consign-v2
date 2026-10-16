<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Auth\OwnerAccess;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;

final class MallApp
{
    public const MISS_HIDDEN = 'hidden';

    public const MISS_MALL = 'missing-mall';

    public const MISS_RECORD = 'missing-record';

    public const MISS_ROUTE = 'unknown-route';

    private ?TenantRecord $tenant = null;

    private bool $roled = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Session $session,
        private readonly View $view,
        private readonly Request $request,
        private readonly OwnerAccess $access,
        private readonly MallSettings $mall,
        private readonly ActiveRegister $drawers,
        private readonly TenantLookup $tenants,
        private readonly FrontDoor $door,
    ) {
    }

    public function request(): Request
    {
        return $this->request;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function show(string $template, array $data = []): void
    {
        $slug = $data['slug'] ?? null;
        $data['nav'] = is_string($slug) && $this->sessionSlug() === $slug;
        $data['csrf'] = $this->door->token();
        $data['error'] = $data['error'] ?? null;
        $notice = $this->chrome()->takeFlash('notice');
        $warning = $this->chrome()->takeFlash('warning');
        $data['path'] = $this->request->path;
        $data['today'] = $this->roled ? $this->mall->today() : '';
        if (is_string($slug)) {
            $this->registerBar($data, $slug, $notice, $warning);
        }
        $data['notice'] = $notice;
        $data['warning'] = $warning;
        $data['actorRole'] = $this->door->actor()->role();
        $data['previewAs'] = $this->chrome()->previewAs(is_string($slug) ? $slug : '');
        $data['showBrand'] = $this->roled ? $this->mall->enabled('use_brand') : true;
        $data['boothRentals'] ??= $this->roled ? $this->mall->isRentalsEnabled() : true;
        $data['catalogNav'] = $this->roled ? $this->mall->getCatalogLinks() : [];
        $data['attributeLists'] = $this->roled ? $this->mall->getEnabledLists() : [];
        $this->view->render('Shared/Web/layout', $data + ['body' => $template]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function registerBar(array &$data, string $slug, ?string $notice, ?string $warning): void
    {
        $away = $this->chrome()->away($data, $slug, $this->roled);
        if ($away !== null) {
            $this->redirect($away, $notice, $warning);
        }
    }

    public function notFound(string $reason = self::MISS_RECORD): never
    {
        $this->door->miss($reason);
    }

    public function releaseClose(string $slug): never
    {
        $this->session->delete('close_prior:' . $slug);
        $this->redirect('/' . $slug . '/registers');
    }

    public function redirect(string $path, ?string $notice = null, ?string $warning = null): never
    {
        $this->chrome()->keepFlash('notice', $notice);
        $this->chrome()->keepFlash('warning', $warning);
        $this->door->go($path);
    }

    public function open(string $slug, bool $books): TenantRecord
    {
        $this->refuseForeign($slug);
        $cached = $this->cachedTenant($slug, $books);
        if ($cached !== null) {
            $this->refuseClosedRentals($slug);

            return $cached;
        }
        $tenant = $this->requireTenant($slug);
        $this->enterBooks($books, $slug, $tenant);
        $this->tenant = $tenant;
        $this->refuseClosedRentals($slug);

        return $tenant;
    }

    public function signIn(string $slug, string $username, string $password): bool
    {
        return $this->door->signIn($slug, $username, $password);
    }

    public function signOut(): void
    {
        $this->door->signOut();
    }

    public function checkCsrf(): void
    {
        $this->door->checkCsrf();
    }

    public function catchRefusal(\Throwable $error): string
    {
        return $this->door->catchRefusal($error);
    }

    /**
     * @return list<string>
     */
    public function getCompanyLines(): array
    {
        return $this->mall->getCompanyLines();
    }

    private function refuseClosedRentals(string $slug): void
    {
        if (!$this->roled) {
            return;
        }
        $home = $this->chrome()->rentHome($slug, true, $this->mall->isRentalsEnabled());
        if ($home === null) {
            return;
        }
        $this->redirect($home, null, 'This mall does not charge booth rent.');
    }

    private function refuseForeign(string $slug): void
    {
        if ($this->access->isForeign($this->sessionSlug(), $slug)) {
            $this->notFound(self::MISS_HIDDEN);
        }
    }

    private function cachedTenant(string $slug, bool $books): ?TenantRecord
    {
        if ($this->tenant !== null && $this->tenant->slug === $slug && (!$books || $this->roled)) {
            return $this->tenant;
        }

        return null;
    }

    private function requireTenant(string $slug): TenantRecord
    {
        $tenant = $this->tenants->find($slug);
        if ($tenant === null) {
            $this->notFound(self::MISS_MALL);
        }

        return $tenant;
    }

    private function enterBooks(bool $books, string $slug, TenantRecord $tenant): void
    {
        if (!$books) {
            return;
        }
        if ($this->sessionSlug() !== $slug) {
            $this->redirect('/' . $slug . '/login');
        }
        if (!$this->door->credentialsMatch($tenant)) {
            $this->signOut();
            $this->redirect('/' . $slug . '/login');
        }
        if (!$this->door->actor()->permitted($slug, $this->request->path, $this->request->method)) {
            $this->notFound(self::MISS_HIDDEN);
        }
        if ($this->roled) {
            return;
        }
        $this->pdo->exec('SET search_path TO ' . $tenant->schema . ', pg_catalog, extensions');
        $this->pdo->exec('SET ROLE ' . $tenant->role);
        $this->roled = true;
    }

    private function sessionSlug(): ?string
    {
        return $this->door->actor()->slug();
    }

    private function chrome(): MallChrome
    {
        return new MallChrome($this->drawers, $this->session, $this->door, $this->request);
    }

}
