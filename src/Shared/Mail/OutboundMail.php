<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

use Psr\Log\LoggerInterface;

final class OutboundMail
{
    public const SMTP_NOT_CONFIGURED = 'smtp is not configured';

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function configured(): bool
    {
        return $this->settings() !== null;
    }

    public function captured(): bool
    {
        $file = getenv('STALL_MAIL_FILE');

        return is_string($file) && $file !== '';
    }

    public function send(string $to, string $subject, string $body): void
    {
        try {
            $this->deliver($to, $subject, $body);
        } catch (MailDeliveryFailed | \InvalidArgumentException | \RuntimeException $failed) {
            $this->logger->error($failed->getMessage());
        }
    }

    public function deliver(string $to, string $subject, string $body): void
    {
        if ($this->unsafe($to) || $this->unsafe($subject) || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $to) !== 1) {
            throw new \InvalidArgumentException('mail was refused');
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
            throw new \RuntimeException('mail file write failed');
        }
        $written = file_put_contents($path, 'To: ' . $to . "\nSubject: " . $subject . "\n\n" . $body . "\n", FILE_APPEND);
        if ($written === false) {
            throw new \RuntimeException('mail file write failed');
        }
    }

    private function smtp(string $to, string $subject, string $body): void
    {
        $settings = $this->settings();
        if ($settings === null) {
            throw new \RuntimeException(self::SMTP_NOT_CONFIGURED);
        }
        try {
            (new SmtpClient(
                $settings['host'],
                $settings['port'],
                $settings['username'],
                $settings['password'],
                $settings['from'],
            ))->send($to, $subject, $body);
        } catch (MailDeliveryFailed $failed) {
            throw $failed;
        } catch (\Throwable) {
            throw new \RuntimeException('smtp send failed');
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
        if ($username === '' || $password === '' || $from === '') {
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
