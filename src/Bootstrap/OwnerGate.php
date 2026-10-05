<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use PDO;
use Psr\Log\LoggerInterface;

final class OwnerGate
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Session $session,
        private readonly LoggerInterface $logger,
        private readonly Request $request,
        private readonly TenantLookup $tenants,
        private readonly MissingPage $missing,
        private readonly View $view,
    ) {
    }

    public function signIn(string $slug, string $username, string $password): bool
    {
        $tenant = $this->tenants->find($slug);
        if ($tenant === null) {
            $this->missing->render(MallApp::MISS_MALL, $this->token());
        }
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $username) !== 1) {
            $this->logger->info('owner sign-in refused', ['slug' => $slug]);

            return false;
        }
        $recent = $this->pdo->prepare(
            "SELECT count(*)
             FROM platform.owner_sign_in_failures
             WHERE tenant_id = ?::uuid
               AND username = ?
               AND failed_at > clock_timestamp() - interval '15 minutes'"
        );
        $recent->execute([$tenant->id, $username]);
        if ((int) $recent->fetchColumn() >= 5) {
            $this->logger->info('owner sign-in locked', ['slug' => $slug, 'username' => $username]);
            throw new SignInLocked('Too many sign-in attempts. Try again in a few minutes.');
        }
        $statement = $this->pdo->prepare(
            'SELECT password_hash FROM platform.owners WHERE tenant_id = ?::uuid AND username = ?'
        );
        $statement->execute([$tenant->id, $username]);
        $hash = $statement->fetchColumn();
        if (!is_string($hash) || !password_verify($password, $hash)) {
            $failed = $this->pdo->prepare(
                'INSERT INTO platform.owner_sign_in_failures (tenant_id, username) VALUES (?::uuid, ?)'
            );
            $failed->execute([$tenant->id, $username]);
            $this->logger->info('owner sign-in refused', ['slug' => $slug]);

            return false;
        }
        $clear = $this->pdo->prepare(
            'DELETE FROM platform.owner_sign_in_failures WHERE tenant_id = ?::uuid AND username = ?'
        );
        $clear->execute([$tenant->id, $username]);
        $now = (string) time();
        $this->session->regenerateId(true);
        $this->session->set('owner_slug', $slug);
        $this->session->set('owner_name', $username);
        $this->session->set('signed_in_at', $now);
        $this->session->set('last_seen_at', $now);
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
        if (is_string($stored) && $stored !== '' && hash_equals($stored, $sent)) {
            return;
        }
        $this->logger->warning('csrf token refused', ['path' => $this->request->path]);
        http_response_code(400);
        $slug = $this->session->get('owner_slug');
        $home = is_string($slug) ? $slug : '';
        $this->view->render('Shared/Web/layout', [
            'title' => 'Form expired',
            'displayName' => '',
            'slug' => $home,
            'nav' => $home !== '',
            'csrf' => $this->token(),
            'error' => 'The form expired. Go back and try again.',
            'notice' => null,
            'warning' => null,
            'path' => $this->request->path,
            'body' => 'Shared/Web/expired',
            'showSearch' => false,
        ]);
        throw new ResponseSent('csrf');
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

    public function token(): string
    {
        $token = $this->session->get('csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(16));
            $this->session->set('csrf', $token);
        }

        return $token;
    }
}
