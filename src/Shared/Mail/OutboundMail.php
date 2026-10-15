<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

use Psr\Log\LoggerInterface;

final class OutboundMail
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function send(string $to, string $subject, string $body): void
    {
        if ($this->unsafe($to) || $this->unsafe($subject) || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $to) !== 1) {
            $this->logger->error('mail was refused');

            return;
        }
        $file = getenv('STALL_MAIL_FILE');
        if (is_string($file) && $file !== '') {
            $this->file($file, $to, $subject, $body);

            return;
        }
        $this->smtp($to, $subject, $body);
    }

    private function file(string $path, string $to, string $subject, string $body): void
    {
        if (is_dir($path)) {
            $this->logger->error('mail file write failed', ['path' => $path]);

            return;
        }
        $written = file_put_contents($path, 'To: ' . $to . "\nSubject: " . $subject . "\n\n" . $body . "\n", FILE_APPEND);
        if ($written === false) {
            $this->logger->error('mail file write failed', ['path' => $path, 'error' => error_get_last()]);
        }
    }

    private function smtp(string $to, string $subject, string $body): void
    {
        $settings = $this->settings();
        if ($settings === null) {
            $this->logger->error('smtp is not configured');

            return;
        }
        try {
            (new SmtpClient(
                $settings['host'],
                $settings['port'],
                $settings['username'],
                $settings['password'],
                $settings['from'],
            ))->send($to, $subject, $body);
        } catch (\Throwable $error) {
            $this->logger->error('smtp send failed', ['host' => $settings['host'], 'error' => $error->getMessage()]);
        }
    }

    /**
     * @return array{host: string, port: int, username: string, password: string, from: string}|null
     */
    private function settings(): ?array
    {
        $host = $this->text('STALL_SMTP_HOST');
        $username = $this->text('STALL_SMTP_USERNAME');
        $password = $this->raw('STALL_SMTP_PASSWORD');
        $from = $this->text('STALL_SMTP_FROM');
        if ($host === '' || $username === '' || $password === '' || $from === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9.-]+$/', $host) !== 1 || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $from) !== 1) {
            return null;
        }
        $port = $this->port();
        if ($port === null) {
            return null;
        }

        return [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'from' => $from,
        ];
    }

    private function text(string $name): string
    {
        return trim($this->raw($name));
    }

    private function raw(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? $value : '';
    }

    private function port(): ?int
    {
        $port = getenv('STALL_SMTP_PORT');
        if (!is_string($port) || $port === '') {
            return 587;
        }
        if (ctype_digit($port) !== true) {
            return null;
        }
        $number = (int) $port;
        if ($number < 1 || $number > 65535) {
            return null;
        }

        return $number;
    }

    private function unsafe(string $value): bool
    {
        return str_contains($value, "\r") || str_contains($value, "\n");
    }
}
