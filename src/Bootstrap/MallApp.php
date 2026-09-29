<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Auth\OwnerAccess;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;
use Psr\Log\LoggerInterface;

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
        private readonly LoggerInterface $logger,
        private readonly View $view,
        private readonly Request $request,
        private readonly OwnerAccess $access,
        private readonly MallSettings $mall,
        private readonly ActiveRegister $drawers,
        private readonly TenantLookup $tenants,
    ) {
    }

    public function today(): string
    {
        if (!$this->roled) {
            return '';
        }

        return $this->mall->today();
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
        $data['csrf'] = $this->csrf();
        $data['error'] = $data['error'] ?? null;
        $data['notice'] = $this->takeFlash('notice');
        $data['warning'] = $this->takeFlash('warning');
        $data['path'] = $this->request->path;
        $data['today'] = $this->roled ? $this->mall->today() : '';
        if (is_string($slug)) {
            $chosen = $this->drawers->chosen($slug, $this->roled);
            $data['activeRegister'] = $chosen['name'];
            $data['activeSessionId'] = $chosen['id'];
            $data['chooseRegister'] = $chosen['choose'];
            $data['leftOpen'] = $this->drawers->leftOpen($this->roled);
            $this->holdForClose($slug, $data['leftOpen']);
            $data['priorCloseStarted'] = $this->session->get('close_prior:' . $slug) === 'yes';
            $onHub = preg_match('#/registers$#', $this->request->path) === 1;
            $data['priorPrompt'] = $data['leftOpen'] !== [] && $data['priorCloseStarted'] !== true && !$onHub;
        }
        $this->view->render('Shared/Web/layout', $data + ['body' => $template]);
    }

    public function notFound(string $reason = self::MISS_RECORD): never
    {
        http_response_code(404);
        $homeTenant = $this->sessionTenant();
        $pathTenant = $this->pathTenant();
        $displayName = '';
        $home = '';
        $signIn = '';
        if ($homeTenant !== null) {
            $displayName = $homeTenant->displayName;
            $home = $homeTenant->slug;
        } elseif ($pathTenant !== null) {
            $displayName = $pathTenant->displayName;
            $signIn = $pathTenant->slug;
        }
        $this->recordMiss($reason, $reason === self::MISS_ROUTE && $pathTenant === null);
        $this->view->render('Shared/Web/layout', [
            'title' => 'Not found',
            'displayName' => $displayName,
            'slug' => $home,
            'nav' => $home !== '',
            'csrf' => $home !== '' ? $this->csrf() : '',
            'error' => null,
            'notice' => null,
            'warning' => null,
            'path' => $this->request->path,
            'body' => 'Shared/Web/not-found',
            'robots' => 'noindex',
            'showSearch' => false,
            'homeSlug' => $home,
            'signInSlug' => $signIn,
            'back' => $this->backPath(),
        ]);
        throw new ResponseSent('not found');
    }

    /**
     * @param list<array{id: string, name: string, opened: string}> $leftOpen
     */
    private function holdForClose(string $slug, array $leftOpen): void
    {
        $key = 'close_prior:' . $slug;
        if ($leftOpen === []) {
            $this->session->delete($key);

            return;
        }
        $id = $leftOpen[0]['id'];
        if (preg_match('#/pos(/|$)#', $this->request->path) === 1) {
            return;
        }
        if ($this->closingRegister($leftOpen) !== null) {
            $this->session->set($key, 'yes');

            return;
        }
        if ($this->session->get($key) === 'yes') {
            $this->redirect('/' . $slug . '/registers?close=' . rawurlencode($id));
        }
    }

    /**
     * @param list<array{id: string, name: string, opened: string}> $leftOpen
     */
    private function closingRegister(array $leftOpen): ?string
    {
        $path = $this->request->path;
        if (preg_match('#/registers/([0-9a-fA-F-]{36})/close$#', $path, $match) === 1) {
            return $match[1];
        }
        if (preg_match('#/registers$#', $path) !== 1) {
            return null;
        }
        $query = $this->request->query('close');
        foreach ($leftOpen as $row) {
            if ($row['id'] === $query) {
                return $query;
            }
        }

        return null;
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
        $tenant = $this->tenants->find($slug);
        if ($tenant === null) {
            $this->notFound(self::MISS_MALL);
        }
        $statement = $this->pdo->prepare(
            'SELECT password_hash FROM platform.owners WHERE tenant_id = ?::uuid AND username = ?'
        );
        $statement->execute([$tenant->id, $username]);
        $hash = $statement->fetchColumn();
        if (!is_string($hash) || !password_verify($password, $hash)) {
            $this->logger->info('owner sign-in refused', ['slug' => $slug]);
            return false;
        }
        $this->session->regenerateId(true);
        $this->session->set('owner_slug', $slug);
        $this->session->set('owner_name', $username);
        $this->session->set('csrf', bin2hex(random_bytes(16)));

        return true;
    }

    public function signOut(): void
    {
        $this->session->destroy();
    }

    public function checkCsrf(): void
    {
        $sent = $this->request->field('csrf');
        $stored = $this->session->get('csrf');
        if (!is_string($stored) || $stored === '' || !hash_equals($stored, $sent)) {
            $this->logger->warning('csrf token refused');
            http_response_code(400);
            echo 'The form expired. Go back and try again.';
            throw new ResponseSent('csrf');
        }
    }

    public function catchRefusal(\Throwable $error): string
    {
        $detail = $error->getPrevious();
        $this->logger->info('ledger refused a post', [
            'message' => $error->getMessage(),
            'database' => $detail instanceof \Throwable ? $detail->getMessage() : null,
        ]);

        return $error->getMessage();
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

    private function recordMiss(string $reason, bool $debug): void
    {
        $context = [
            'method' => $this->request->method,
            'path' => $this->request->path,
            'reason' => $reason,
        ];
        if ($debug) {
            $this->logger->debug('page not found', $context);

            return;
        }
        $this->logger->info('page not found', $context);
    }

    private function sessionTenant(): ?TenantRecord
    {
        $slug = $this->sessionSlug();
        if ($slug === null) {
            return null;
        }

        return $this->tenants->find($slug);
    }

    private function pathTenant(): ?TenantRecord
    {
        if (preg_match('#^/([a-z][a-z0-9-]{0,62})(?:/|$)#', $this->request->path, $match) !== 1) {
            return null;
        }

        return $this->tenants->find($match[1]);
    }

    private function backPath(): string
    {
        $referer = $this->request->referer;
        if ($referer === '' || $referer === $this->request->path) {
            return '';
        }

        return $referer;
    }

    private function sessionSlug(): ?string
    {
        $slug = $this->session->get('owner_slug');
        if (!is_string($slug) || $slug === '') {
            return null;
        }

        return $slug;
    }

    private function csrf(): string
    {
        $token = $this->session->get('csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(16));
            $this->session->set('csrf', $token);
        }

        return $token;
    }
}
