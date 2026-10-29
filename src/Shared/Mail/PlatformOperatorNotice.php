<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

use Psr\Log\LoggerInterface;

/**
 * Emails STALL_PLATFORM_ADMIN_EMAIL when Gmail/SMTP or a server fault needs an operator.
 * Uses the platform mailbox (env), never the mall Gmail row, so a bad store password can still alert.
 */
final class PlatformOperatorNotice
{
    private static bool $sending = false;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function mailFailed(string $purpose, \Throwable $error, array $context = []): void
    {
        if ($error instanceof MailDeliveryFailed) {
            $context['status'] = $error->status();
            $context['stage'] = $error->stage();
        }
        $context['message'] = $error->getMessage();
        $this->logger->error($purpose, $context);
        $lines = [
            'Perfect Consign could not send mail.',
            '',
            'Purpose: ' . $purpose,
            'Error: ' . $error->getMessage(),
        ];
        if ($error instanceof MailDeliveryFailed) {
            $lines[] = 'SMTP status: ' . (string) $error->status();
            $lines[] = 'SMTP stage: ' . $error->stage();
        }
        foreach ($context as $key => $value) {
            if ($key === 'message' || $key === 'status' || $key === 'stage') {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $lines[] = $key . ': ' . (string) $value;
            }
        }
        $this->alert('Perfect Consign: mail delivery failed', implode("\n", $lines) . "\n");
    }

    /**
     * Caller must log first. This only emails the platform admin.
     *
     * @param array<string, mixed> $context
     */
    public function serverFailed(string $summary, array $context = []): void
    {
        $lines = [
            'Perfect Consign hit a server error.',
            '',
            'Summary: ' . $summary,
        ];
        foreach (['request_id', 'method', 'path', 'file', 'line', 'message'] as $key) {
            if (!array_key_exists($key, $context)) {
                continue;
            }
            $value = $context[$key];
            if (is_scalar($value) || $value === null) {
                $lines[] = $key . ': ' . (string) $value;
            }
        }
        if (isset($context['trace']) && is_string($context['trace']) && $context['trace'] !== '') {
            $lines[] = '';
            $lines[] = 'Trace:';
            $lines[] = $context['trace'];
        }
        $this->alert('Perfect Consign: server error', implode("\n", $lines) . "\n");
    }

    private function alert(string $subject, string $body): void
    {
        if (self::$sending) {
            $this->logger->error('platform operator notice skipped while one is already sending');

            return;
        }
        $to = $this->adminEmail();
        if ($to === null) {
            return;
        }
        self::$sending = true;
        try {
            $this->deliver($to, $subject, $body);
        } catch (\Throwable $error) {
            $this->logger->error('platform operator notice was not sent', [
                'message' => $error->getMessage(),
            ]);
        } finally {
            self::$sending = false;
        }
    }

    private function deliver(string $to, string $subject, string $body): void
    {
        $file = getenv('STALL_MAIL_FILE');
        if (is_string($file) && $file !== '') {
            (new OutboundMail($this->logger))->deliver($to, $subject, $body);

            return;
        }
        $gmail = $this->platformMailbox();
        if ($gmail !== null) {
            (new SmtpClient(
                $gmail['host'],
                $gmail['port'],
                $gmail['username'],
                $gmail['password'],
                $gmail['from'],
            ))->send($to, $subject, $body);

            return;
        }
        (new OutboundMail($this->logger))->deliver($to, $subject, $body);
    }

    /**
     * @return array{host: string, port: int, username: string, password: string, from: string}|null
     */
    private function platformMailbox(): ?array
    {
        $address = getenv('STALL_GMAIL_ADDRESS');
        $password = getenv('STALL_GMAIL_APP_PASSWORD');
        if (!is_string($address) || $address === '' || !is_string($password) || $password === '') {
            return null;
        }
        $password = str_replace(' ', '', $password);
        if ($password === '') {
            return null;
        }

        return [
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => $address,
            'password' => $password,
            'from' => $address,
        ];
    }

    private function adminEmail(): ?string
    {
        $email = getenv('STALL_PLATFORM_ADMIN_EMAIL');
        if (!is_string($email)) {
            return null;
        }
        $email = strtolower(trim($email));
        if ($email === '' || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1) {
            return null;
        }

        return $email;
    }
}
