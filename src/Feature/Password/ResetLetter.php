<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;
use Psr\Log\LoggerInterface;

final class ResetLetter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabaseRole $roles,
        private readonly ResetTokens $tokens,
        private readonly LetterMailbox $mailbox,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendReset(TenantRecord $tenant, LoginAccount $account, string $origin, LoginAccounts $accounts): bool
    {
        try {
            $account = $accounts->forReset($account);
            if ($account === null) {
                return true;
            }
            $this->enter($tenant);
            try {
                if (!$this->mailbox->ready()) {
                    throw new \RuntimeException('Mail is not set up.');
                }
            } finally {
                $this->roles->reset();
            }

            return $this->deliver($tenant, $account, $origin);
        } catch (\Throwable $error) {
            $this->logger->error('the reset mailbox could not be read', [
                'slug' => $tenant->slug,
                'error' => $error->getMessage(),
            ]);

            return true;
        }
    }

    public function deliver(TenantRecord $tenant, LoginAccount $account, string $origin): bool
    {
        if (preg_match('#\Ahttps?://[A-Za-z0-9.-]+(?::[0-9]{1,5})?\z#', $origin) !== 1) {
            $this->logger->error('reset link origin is missing', ['slug' => $tenant->slug]);

            return false;
        }
        $token = $this->tokens->issue($tenant, $account);
        $this->enter($tenant);
        try {
            $this->mailbox->send(
                $account->email,
                'Reset your password',
                $this->letter($tenant, $account, $origin, $token),
                'password reset mail was refused',
            );
        } catch (\Throwable $error) {
            $this->logger->error('password reset mail was refused', [
                'slug' => $tenant->slug,
                'message' => $error->getMessage(),
            ]);

            return false;
        } finally {
            $this->roles->reset();
        }

        return true;
    }

    private function enter(TenantRecord $tenant): void
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $tenant->schema) !== 1) {
            throw new \RuntimeException('the mall schema is not usable');
        }
        $this->roles->reset();
        if ($this->pdo->exec('SET search_path TO ' . $tenant->schema . ', pg_catalog, extensions') === false) {
            throw new \RuntimeException('the mall schema could not be used');
        }
        $this->roles->assume($tenant->role);
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
