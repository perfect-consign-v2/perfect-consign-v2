<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

final class SmtpClient
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $from,
    ) {
    }

    public function send(string $to, string $subject, string $body): void
    {
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
            $this->expect($socket, 220);
            $this->command($socket, 'EHLO perfectconsign', 250);
            $this->command($socket, 'STARTTLS', 220);
            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new \RuntimeException('smtp send failed');
            }
            $this->command($socket, 'EHLO perfectconsign', 250);
            $this->command($socket, 'AUTH LOGIN', 334);
            $this->command($socket, base64_encode($this->username), 334);
            $this->command($socket, base64_encode($this->password), 235);
            $this->command($socket, 'MAIL FROM:<' . $this->from . '>', 250);
            $this->command($socket, 'RCPT TO:<' . $to . '>', 250);
            $this->command($socket, 'DATA', 354);
            $this->write($socket, $this->payload($to, $subject, $body));
            $this->expect($socket, 250);
            $this->command($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }
    }

    private function command(mixed $socket, string $command, int $expect): void
    {
        $this->write($socket, $command . "\r\n");
        $this->expect($socket, $expect);
    }

    private function expect(mixed $socket, int $expect): void
    {
        if ($this->read($socket) !== $expect) {
            throw new \RuntimeException('smtp send failed');
        }
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

    private function payload(string $to, string $subject, string $body): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $body);
        $stuffed = preg_replace('/^\./m', '..', $text);
        if (!is_string($stuffed)) {
            throw new \RuntimeException('smtp send failed');
        }

        return 'From: <' . $this->from . ">\r\n"
            . 'To: <' . $to . ">\r\n"
            . 'Subject: ' . $subject . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . str_replace("\n", "\r\n", $stuffed)
            . "\r\n.\r\n";
    }
}
