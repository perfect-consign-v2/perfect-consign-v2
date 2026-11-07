<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Shared\Mail\PlatformMailbox;
use Psr\Log\LoggerInterface;

final class AdminResetMail
{
    public function __construct(
        private readonly AdminResetTokens $tokens,
        private readonly PlatformMailbox $platformMail,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{id: string, email: string, hash: string} $row
     */
    public function send(array $row, string $origin): void
    {
        if (preg_match('#\Ahttps?://[A-Za-z0-9.-]+(?::[0-9]{1,5})?\z#', $origin) !== 1) {
            $this->logger->error('admin reset link origin is missing');
            throw new \RuntimeException(AdminPasswordRecovery::MAIL_FAILED);
        }
        if (!$this->platformMail->ready()) {
            $this->logger->error('admin reset mailbox is not set up');
            throw new \RuntimeException(AdminPasswordRecovery::MAIL_FAILED);
        }
        $token = $this->tokens->issue($row['id']);
        $body = "A password reset was requested for the Perfect Consign platform admin.\n\n"
            . "Open this link within 30 minutes:\n"
            . $origin . '/admin/reset/' . $token . "\n\n"
            . "If you did not ask, you can ignore this message.\n";
        try {
            $this->platformMail->send(
                $row['email'],
                'Reset your Perfect Consign admin password',
                $body,
                'admin reset mail was refused',
            );
        } catch (\Throwable $error) {
            $this->logger->error('admin reset mail was refused', [
                'message' => $error->getMessage(),
            ]);
            throw new \RuntimeException(AdminPasswordRecovery::MAIL_FAILED, 0, $error);
        }
        $this->logger->info('admin reset mail was sent', [
            'email' => $row['email'],
        ]);
    }
}
