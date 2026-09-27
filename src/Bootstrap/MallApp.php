<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Auth\OwnerAccess;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;
use Psr\Log\LoggerInterface;

final class MallApp
{
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
        $data['csrf'] = $this->csrf();
        $data['error'] = $data['error'] ?? null;
        $data['notice'] = $this->takeNotice();
        $data['path'] = $this->request->path;
        $data['today'] = $this->roled ? $this->mall->today() : '';
        $this->view->render('Shared/Web/layout', $data + ['body' => $template]);
    }

    public function notFound(): never
    {
        http_response_code(404);
        $this->view->render('Shared/Web/layout', [
            'title' => 'Not found',
            'displayName' => '',
            'slug' => '',
            'nav' => false,
            'csrf' => '',
            'error' => null,
            'notice' => null,
            'path' => $this->request->path,
            'body' => 'Shared/Web/not-found',
        ]);
        throw new ResponseSent('not found');
    }

    public function redirect(string $path, ?string $notice = null): never
    {
        if ($notice !== null && $notice !== '') {
            $this->session->set('notice', $notice);
        }
        header('Location: ' . $path, true, 303);
        throw new ResponseSent('redirect');
    }

    private function takeNotice(): ?string
    {
        $notice = $this->session->get('notice');
        if ($this->session->has('notice')) {
            $this->session->delete('notice');
        }
        if (!is_string($notice) || $notice === '') {
            return null;
        }

        return $notice;
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
        $tenant = $this->findTenant($slug);
        if ($tenant === null) {
            $this->notFound();
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

    public function catchRefusal(DatabaseRefusal $error): string
    {
        $this->logger->info('ledger refused a post', ['message' => $error->getMessage()]);

        return $error->getMessage();
    }

    private function findTenant(string $slug): ?TenantRecord
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $slug) !== 1) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT id, slug, schema_name, display_name FROM platform.tenants WHERE slug = ?'
        );
        $statement->execute([$slug]);
        $row = $statement->fetch();
        if (!is_array($row) || !is_string($row['schema_name'] ?? null)) {
            return null;
        }
        $schema = $row['schema_name'];
        $id = $row['id'] ?? null;
        $rowSlug = $row['slug'] ?? null;
        $displayName = $row['display_name'] ?? null;
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            return null;
        }
        if (!is_string($id) || !is_string($rowSlug) || !is_string($displayName)) {
            return null;
        }
        $role = TenantRecord::roleFor($rowSlug, $schema);

        return new TenantRecord($id, $rowSlug, $schema, $role, $displayName);
    }

    private function refuseForeign(string $slug): void
    {
        if ($this->access->isForeign($this->sessionSlug(), $slug)) {
            $this->notFound();
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
        $tenant = $this->findTenant($slug);
        if ($tenant === null) {
            $this->notFound();
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
