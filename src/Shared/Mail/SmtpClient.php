<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

final class SmtpClient
{
    /**
     * @param null|\Closure(mixed): bool $crypto
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $from,
        private readonly ?\Closure $crypto = null,
    ) {
    }

    public function send(string $to, string $subject, string $body): void
    {
        $to = $this->line($to);
        $subject = $this->line($subject);
        $from = $this->line($this->from);
        $username = $this->line($this->username);
        $password = $this->line($this->password);
        $errno = 0;
        $error = '';
        $socket = stream_socket_client(
            'tcp://' . $this->host . ':' . $this->port,
            $errno,
            $error,
            15,
            STREAM_CLIENT_CONNECT,
            stream_context_create([
                'ssl' => [
                    'peer_name' => $this->host,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'SNI_enabled' => true,
                ],
            ])
        );
        if ($socket === false || $errno !== 0 || $error !== '') {
            throw new \RuntimeException('smtp send failed');
        }
        stream_set_timeout($socket, 15);
        try {
            $this->expect($socket, 220, 'greeting');
            $this->command($socket, 'EHLO perfectconsign', 250, 'ehlo');
            $this->command($socket, 'STARTTLS', 220, 'starttls');
            $crypto = $this->crypto;
            $secured = $crypto !== null
                ? $crypto($socket)
                : stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($secured !== true) {
                throw new \RuntimeException('smtp send failed');
            }
            $this->command($socket, 'EHLO perfectconsign', 250, 'ehlo');
            $this->command($socket, 'AUTH LOGIN', 334, 'auth');
            $this->command($socket, base64_encode($username), 334, 'auth');
            $this->command($socket, base64_encode($password), 235, 'auth');
            $this->command($socket, 'MAIL FROM:<' . $from . '>', 250, 'mail');
            $this->command($socket, 'RCPT TO:<' . $to . '>', 250, 'rcpt');
            $this->command($socket, 'DATA', 354, 'data');
            $this->write($socket, $this->payload($from, $to, $subject, $body));
            $this->expect($socket, 250, 'data');
            $this->command($socket, 'QUIT', 221, 'quit');
        } finally {
            fclose($socket);
        }
    }

    private function command(mixed $socket, string $command, int $expect, string $stage): void
    {
        $this->write($socket, $command . "\r\n");
        $this->expect($socket, $expect, $stage);
    }

    private function expect(mixed $socket, int $expect, string $stage): void
    {
        $status = $this->read($socket);
        if ($status === $expect) {
            return;
        }
        if ($status >= 400) {
            throw new MailDeliveryFailed($status, $stage);
        }

        throw new \RuntimeException('smtp send failed');
    }

    private function read(mixed $socket): int
    {
        if (!is_resource($socket)) {
            throw new \RuntimeException('smtp send failed');
        }
        for ($line = 0; $line < 40; $line++) {
            $text = fgets($socket, 515);
            $match = [];
            if (!is_string($text) || preg_match('/^(\d{3})([\s-])/', $text, $match) !== 1) {
                throw new \RuntimeException('smtp send failed');
            }
            if ($match[2] !== ' ') {
                if ($match[2] !== '-') {
                    throw new \RuntimeException('smtp send failed');
                }

                continue;
            }
            return (int) $match[1];
        }

        throw new \RuntimeException('smtp send failed');
    }

    private function write(mixed $socket, string $text): void
    {
        if (!is_resource($socket) || fwrite($socket, $text) === false) {
            throw new \RuntimeException('smtp send failed');
        }
    }

    private function line(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }

    private function payload(string $from, string $to, string $subject, string $body): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $body);
        $stuffed = preg_replace('/^\./m', '..', $text);
        if (!is_string($stuffed)) {
            throw new \RuntimeException('smtp send failed');
        }

        $domain = 'localhost';
        if (preg_match('/@([A-Za-z0-9.-]+)\z/', $from, $match) === 1) {
            $domain = strtolower($match[1]);
        }
        $messageId = bin2hex(random_bytes(16)) . '@' . $domain;

        return 'Date: ' . gmdate('D, d M Y H:i:s O') . "\r\n"
            . 'Message-ID: <' . $messageId . ">\r\n"
            . 'From: <' . $from . ">\r\n"
            . 'To: <' . $to . ">\r\n"
            . 'Subject: ' . $subject . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . str_replace("\n", "\r\n", $stuffed)
            . "\r\n.\r\n";
    }
}
