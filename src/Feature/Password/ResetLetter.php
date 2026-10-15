<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Tenant\TenantRecord;
use Psr\Log\LoggerInterface;

final class ResetLetter
{
    public function __construct(
        private readonly ResetTokens $tokens,
        private readonly OutboundMail $mail,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function deliver(TenantRecord $tenant, LoginAccount $account, string $origin): void
    {
        if (preg_match('#\Ahttps?://[A-Za-z0-9.-]+(?::[0-9]{1,5})?\z#', $origin) !== 1) {
            $this->logger->error('reset link origin is missing', ['slug' => $tenant->slug]);

            return;
        }
        $token = $this->tokens->issue($tenant, $account);
        $this->mail->send($account->email, 'Reset your password', $this->letter($tenant, $account, $origin, $token));
    }

    private function letter(TenantRecord $tenant, LoginAccount $account, string $origin, string $token): string
    {
        $link = $origin . '/' . $tenant->slug . '/reset/' . $token;

        return 'A password reset was requested for ' . $account->username . ' at ' . $tenant->displayName . ".\n\n"
            . "Open this link within 30 minutes:\n"
            . $link . "\n\n"
            . "If you did not ask, you can ignore this message.\n";
    }
}
