<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectApp\Session\Session;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use PDO;
use Psr\Log\LoggerInterface;

final class AdminApp
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Session $session,
        private readonly View $view,
        private readonly Request $request,
        private readonly LoggerInterface $logger,
        private readonly AdminSession $admins,
        private readonly AdminAudit $audit,
    ) {
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function audit(): AdminAudit
    {
        return $this->audit;
    }

    public function session(): AdminSession
    {
        return $this->admins;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @return array{id: string, email: string, version: string}
     */
    public function requireAdmin(): array
    {
        $current = $this->admins->current();
        if ($current === null) {
            $this->redirect('/admin/login');
        }
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, email, credentials_version
             FROM platform.admins
             WHERE id = ?::uuid'
        );
        $statement->execute([$current['id']]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            $this->admins->clear();
            $this->redirect('/admin/login');
        }
        $version = $row['credentials_version'] ?? null;
        if (is_int($version)) {
            $version = (string) $version;
        }
        $email = $row['email'] ?? null;
        $id = $row['id'] ?? null;
        if (!is_string($id) || !is_string($email) || !is_string($version) || $version !== $current['version']) {
            $this->admins->clear();
            $this->redirect('/admin/login');
        }
        $this->admins->touch();

        return ['id' => $id, 'email' => $email, 'version' => $version];
    }

    public function checkCsrf(): void
    {
        $sent = $this->request->field('csrf');
        $stored = $this->session->get('csrf');
        if (is_string($stored) && $stored !== '' && hash_equals($stored, $sent)) {
            return;
        }
        $this->logger->warning('csrf token refused', ['path' => $this->request->path]);
        http_response_code(400);
        $this->show('Feature/Admin/expired', [
            'title' => 'Form expired',
            'error' => 'The form expired. Go back and try again.',
        ], false);
        throw new ResponseSent('csrf');
    }

    public function token(): string
    {
        $token = $this->session->get('csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(16));
            $this->session->set('csrf', $token);
        }

        return $token;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function show(string $template, array $data = [], bool $nav = true): void
    {
        $data['csrf'] = $this->token();
        $data['error'] = $data['error'] ?? null;
        $data['notice'] = $this->takeFlash('admin_notice');
        $data['warning'] = $this->takeFlash('admin_warning');
        $data['path'] = $this->request->path;
        $data['nav'] = $nav && $this->admins->current() !== null;
        $admin = $this->admins->current();
        $data['adminEmail'] = $admin['email'] ?? '';
        $this->view->render('Feature/Admin/layout', $data + ['body' => $template]);
    }

    public function redirect(string $path, ?string $notice = null, ?string $warning = null): never
    {
        $this->keepFlash('admin_notice', $notice);
        $this->keepFlash('admin_warning', $warning);
        header('Location: ' . $path, true, 303);
        throw new ResponseSent('redirect');
    }

    private function keepFlash(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            $this->session->delete($key);

            return;
        }
        $this->session->set($key, $value);
    }

    private function takeFlash(string $key): ?string
    {
        $value = $this->session->get($key);
        $this->session->delete($key);
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
