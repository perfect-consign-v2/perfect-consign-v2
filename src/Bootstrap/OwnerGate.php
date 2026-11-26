<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Shared\Http\Request;
use Psr\Log\LoggerInterface;

final class OwnerGate
{
    public function __construct(
        private readonly Session $session,
        private readonly LoggerInterface $logger,
        private readonly Request $request,
        private readonly TenantLookup $tenants,
        private readonly MissingPage $missing,
        private readonly StoreAccounts $accounts,
        private readonly SignInGate $gate,
    ) {
    }

    /**
     * Signs in a store owner or staff member. $emailTried is the email typed at
     * the root sign-in, for the attempt log.
     */
    public function signIn(string $slug, string $username, string $password, ?string $emailTried = null): bool
    {
        $tenant = $this->tenants->find($slug);
        if ($tenant === null) {
            $this->missing->missingMall($this->token());
        }
        $username = strtolower(trim($username));
        $match = null;
        if (
            preg_match('/^[a-z][a-z0-9-]{0,62}$/', $username) !== 1
            || !$this->gate->attempt(
                SignInAccount::store($tenant->id, $username, $emailTried),
                $password,
                function () use ($tenant, $username, $password, &$match): SignInCheck {
                    $match = $this->accounts->check($tenant, $username, $password);

                    return $match->check;
                },
            )
            || $match === null
        ) {
            $this->logger->info('owner sign-in refused', ['slug' => $slug]);

            return false;
        }
        $this->accounts->open($tenant, $username, $match);

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
        $slug = $this->session->get('owner_slug');
        $home = is_string($slug) ? $slug : '';
        $this->missing->expiredForm($this->token(), $home);
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
