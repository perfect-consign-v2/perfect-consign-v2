<?php

declare(strict_types=1);

namespace PerfectConsign;

use PerfectApp\Session\Session;
use PerfectConsign\Auth\OwnerAccess;
use PerfectConsign\Http\Request;
use PerfectConsign\Http\View;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Tenant\TenantRecord;
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
    ) {
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function show(string $template, array $data = []): void
    {
        $slug = $data['slug'] ?? null;
        $data['nav'] = is_string($slug) && $this->sessionSlug() === $slug;
        $data['csrf'] = $this->csrf();
        $data['error'] = $data['error'] ?? null;
        $this->view->render('layout', $data + ['body' => $template]);
    }

    public function notFound(): never
    {
        http_response_code(404);
        $this->view->render('layout', [
            'title' => 'Not found',
            'displayName' => '',
            'slug' => '',
            'nav' => false,
            'csrf' => '',
            'error' => null,
            'body' => 'not-found',
        ]);
        exit;
    }

    public function redirect(string $path): never
    {
        header('Location: ' . $path, true, 303);
        exit;
    }

    public function open(string $slug, bool $books): TenantRecord
    {
        if ($this->access->isForeign($this->sessionSlug(), $slug)) {
            $this->notFound();
        }
        if ($this->tenant !== null && $this->tenant->slug === $slug && (!$books || $this->roled)) {
            return $this->tenant;
        }
        $tenant = $this->findTenant($slug);
        if ($tenant === null) {
            $this->notFound();
        }
        if ($books && $this->sessionSlug() !== $slug) {
            $this->redirect('/' . $slug . '/login');
        }
        if ($books && !$this->roled) {
            $this->pdo->exec('SET search_path TO ' . $tenant->schema . ', pg_catalog');
            $this->pdo->exec('SET ROLE ' . $tenant->role);
            $this->roled = true;
        }
        $this->tenant = $tenant;

        return $tenant;
    }

    public function books(): LedgerStore
    {
        return new LedgerStore($this->pdo);
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
            exit;
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
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            return null;
        }
        $role = TenantRecord::roleFor((string) $row['slug'], $schema);

        return new TenantRecord((string) $row['id'], (string) $row['slug'], $schema, $role, (string) $row['display_name']);
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
