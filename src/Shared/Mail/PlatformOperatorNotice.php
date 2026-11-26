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
        $context = MailDeliveryFailed::context($error, $context);
        $context['message'] = $error->getMessage();
        $this->logger->error($purpose, $context);
        $lines = [
            'Perfect Consign could not send mail.',
            '',
            'Purpose: ' . $purpose,
            'Error: ' . $error->getMessage(),
        ];
        foreach ($this->smtpLines($error) as $line) {
            $lines[] = $line;
        }
        foreach ($this->contextLines($context) as $line) {
            $lines[] = $line;
        }
        $this->alert('Perfect Consign: mail delivery failed', implode("\n", $lines) . "\n");
    }

    /**
     * @return list<string>
     */
    private function smtpLines(\Throwable $error): array
    {
        if (!$error instanceof MailDeliveryFailed) {
            return [];
        }
        $lines = [
            'SMTP status: ' . (string) $error->status(),
            'SMTP stage: ' . $error->stage(),
        ];
        if ($error->reply() !== '') {
            $lines[] = 'SMTP reply: ' . $error->reply();
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private function contextLines(array $context): array
    {
        $lines = [];
        foreach ($context as $key => $value) {
            if (in_array($key, ['message', 'status', 'stage', 'reply'], true)) {
                continue;
            }
            if (!is_scalar($value) && $value !== null) {
                continue;
            }
            $lines[] = $key . ': ' . (string) $value;
        }

        return $lines;
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

    /**
     * True when the notice was sent, or when there is no platform admin address.
     * False when a notice is already in flight. A failed send throws.
     */
    public function reportJobs(string $subject, string $body): bool
    {
        if (self::$sending) {
            return false;
        }
        $to = self::adminAddress();
        if ($to === null) {
            return true;
        }
        self::$sending = true;
        try {
            $this->deliver($to, $subject, $body);
        } finally {
            self::$sending = false;
        }

        return true;
    }

    private function alert(string $subject, string $body): void
    {
        if (self::$sending) {
            $this->logger->error('platform operator notice skipped while one is already sending');

            return;
        }
        $to = self::adminAddress();
        if ($to === null) {
            return;
        }
        self::$sending = true;
        try {
            $this->deliver($to, $subject, $body);
        } catch (\Throwable $error) {
            $this->logger->error(
                'platform operator notice was not sent',
                MailDeliveryFailed::context($error, [
                    'message' => $error->getMessage(),
                ]),
            );
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

    /**
     * STALL_PLATFORM_ADMIN_EMAIL, lowercased, or null when it is missing or not an address.
     */
    public static function adminAddress(): ?string
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
