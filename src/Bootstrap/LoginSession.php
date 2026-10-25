<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Session\Session;

final class LoginSession
{
    public function __construct(private readonly Session $session)
    {
    }

    public function open(
        string $slug,
        string $tenantId,
        string $name,
        string $role,
        string $accountId,
        string $version,
        string $staffId,
        string $vendorId,
    ): void {
        $now = (string) time();
        $this->session->regenerateId(true);
        $this->session->set('owner_slug', $slug);
        $this->session->set('tenant_id', $tenantId);
        $this->session->set('owner_name', $name);
        $this->session->set('signed_in_at', $now);
        $this->session->set('last_seen_at', $now);
        $this->session->set('csrf', bin2hex(random_bytes(16)));
        $this->session->set('actor_role', $role);
        $this->session->set('actor_account', $accountId);
        $this->session->set('credentials_version', $version);
        $this->session->set('actor_staff', $staffId);
        $this->session->set('actor_vendor', $vendorId);
    }

    public function stamp(string $version): void
    {
        $this->session->regenerateId(true);
        $this->session->set('credentials_version', $version);
        $this->session->set('csrf', bin2hex(random_bytes(16)));
        $this->session->set('last_seen_at', (string) time());
    }
}
