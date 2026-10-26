<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\SmtpClient;
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

    public function sendReset(TenantRecord $tenant, LoginAccount $account, string $origin, LoginAccounts $accounts): bool
    {
        try {
            $mailbox = $accounts->companyMail($tenant);
            $account = $accounts->forReset($account);
            if ($account === null) {
                return true;
            }

            return $this->deliver($tenant, $account, $origin, $mailbox['gmail']);
        } catch (\RuntimeException $error) {
            $this->logger->error('the reset mailbox could not be read', [
                'slug' => $tenant->slug,
                'error' => $error->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * @param array{host: string, port: int, username: string, password: string, from: string}|null $gmail
     */
    public function deliver(TenantRecord $tenant, LoginAccount $account, string $origin, ?array $gmail = null): bool
    {
        if (preg_match('#\Ahttps?://[A-Za-z0-9.-]+(?::[0-9]{1,5})?\z#', $origin) !== 1) {
            $this->logger->error('reset link origin is missing', ['slug' => $tenant->slug]);

            return false;
        }
        $token = $this->tokens->issue($tenant, $account);

        return $this->send($account->email, 'Reset your password', $this->letter($tenant, $account, $origin, $token), $gmail);
    }

    /**
     * @param array{host: string, port: int, username: string, password: string, from: string}|null $gmail
     */
    private function send(string $to, string $subject, string $body, ?array $gmail): bool
    {
        $file = getenv('STALL_MAIL_FILE');
        try {
            if ($gmail === null || (is_string($file) && $file !== '')) {
                $this->mail->deliver($to, $subject, $body);
            } else {
                (new SmtpClient(
                    $gmail['host'],
                    $gmail['port'],
                    $gmail['username'],
                    $gmail['password'],
                    $gmail['from'],
                ))->send($to, $subject, $body);
            }
        } catch (\Throwable $error) {
            $this->logger->error('reset mail was refused', ['message' => $error->getMessage()]);

            return false;
        }

        return true;
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
