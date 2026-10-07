<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Auth\OwnerAccess;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
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
        $notice = $this->takeFlash('notice');
        $warning = $this->takeFlash('warning');
        $data['path'] = $this->request->path;
        $data['today'] = $this->roled ? $this->mall->today() : '';
        if (is_string($slug)) {
            $chosen = $this->drawers->chosen($slug, $this->roled);
            $data['activeRegister'] = $chosen['name'];
            $data['activeSessionId'] = $chosen['id'];
            $data['chooseRegister'] = $chosen['choose'];
            $leftOpen = $this->drawers->leftOpen($this->roled);
            $data['leftOpen'] = $leftOpen;
            $away = $this->door->priorShift($slug, $leftOpen);
            if ($away !== null) {
                $this->redirect($away, $notice, $warning);
            }
            $data['priorCloseStarted'] = $this->session->get('close_prior:' . $slug) === 'yes';
            $onHub = preg_match('#/registers$#', $this->request->path) === 1;
            $data['priorPrompt'] = $leftOpen !== [] && $data['priorCloseStarted'] !== true && !$onHub;
        }
        $data['notice'] = $notice;
        $data['warning'] = $warning;
        $data['catalogNav'] = $this->catalogNav();
        $this->view->render('Shared/Web/layout', $data + ['body' => $template]);
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
        $this->keepFlash('notice', $notice);
        $this->keepFlash('warning', $warning);
        header('Location: ' . $path, true, 303);
        throw new ResponseSent('redirect');
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function catalogNav(): array
    {
        if (!$this->roled) {
            return [];
        }
        $links = [];
        foreach (NameList::all() as $list) {
            if ($this->mall->enabled($list->flag)) {
                $links[] = ['/' . $list->path, $list->plural, 'i-tag'];
            }
        }

        return $links;
    }

    private function keepFlash(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $this->session->set($key, $value);
    }

    private function takeFlash(string $key): ?string
    {
        $value = $this->session->get($key);
        if ($this->session->has($key)) {
            $this->session->delete($key);
        }
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    public function open(string $slug, bool $books): TenantRecord
    {
        $this->refuseForeign($slug);
        $cached = $this->cachedTenant($slug, $books);
        if ($cached !== null) {
            return $cached;
        }
        $tenant = $this->requireTenant($slug);
        $this->enterBooks($books, $slug, $tenant);
        $this->tenant = $tenant;

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
        if ($this->roled) {
            return;
        }
        $this->pdo->exec('SET search_path TO ' . $tenant->schema . ', pg_catalog, extensions');
        $this->pdo->exec('SET ROLE ' . $tenant->role);
        $this->roled = true;
    }

    private function sessionSlug(): ?string
    {
        $slug = $this->session->get('owner_slug');
        if (!is_string($slug) || $slug === '') {
            return null;
        }
        if ($this->sessionStale(time())) {
            $this->session->destroy();

            return null;
        }
        $this->session->set('last_seen_at', (string) time());

        return $slug;
    }

    private function sessionStale(int $now): bool
    {
        return $this->older('signed_in_at', $now, 86400) || $this->older('last_seen_at', $now, 43200);
    }

    private function older(string $key, int $now, int $limit): bool
    {
        $value = $this->session->get($key);
        if (!is_string($value) || ctype_digit($value) !== true) {
            return true;
        }

        return $now - (int) $value >= $limit;
    }

}
