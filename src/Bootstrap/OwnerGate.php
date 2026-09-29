<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
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
    ) {
    }

    public function signIn(string $slug, string $username, string $password): bool
    {
        $tenant = $this->tenants->find($slug);
        if ($tenant === null) {
            $this->missing->render(MallApp::MISS_MALL, $this->token());
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
