<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin\Mfa;

use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Feature\Admin\AdminSession;

final class AdminMfaLogin
{
    public function __construct(
        private readonly AdminMfaStore $store,
        private readonly AdminSession $session,
        private readonly AdminAudit $audit,
        private readonly AdminMfaFailures $failures,
    ) {
    }

    /**
     * @param array<string, string> $detail
     */
    public function open(string $id, string $email, array $detail = []): void
    {
        $version = $this->store->version($id);
        if ($version === null) {
            throw new \RuntimeException('the platform admin session is missing');
        }
        $this->session->open($id, $email, $version);
        $this->audit->record($id, $email, 'admin.sign_in', 'ok', null, null, $detail);
        $this->failures->clearEmail($email);
    }
}
