<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Mail;

use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\SmtpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MailDeliveryTest extends TestCase
{
    public function testABadAddressAndAMissingMailboxAreRefused(): void
    {
        $log = new RecordedMailLog();
        $mail = new OutboundMail($log);
        $this->withEnv(['STALL_MAIL_FILE' => null], static function () use ($mail): void {
            $mail->send("ada@example.com\n", 'Hello', 'Body');
            $mail->send('not-an-email', 'Hello', 'Body');
        });

        self::assertSame(['mail was refused', 'mail was refused'], $log->errors);
    }

    public function testTheFileMailboxKeepsTheLetterAndADirectoryIsLogged(): void
    {
        $log = new RecordedMailLog();
        $mail = new OutboundMail($log);
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-mail-' . bin2hex(random_bytes(4)) . '.txt';
        $this->withEnv(['STALL_MAIL_FILE' => $path], static function () use ($mail): void {
            $mail->send('ada@example.com', 'Hello', "Line\n.");
        });
        $text = file_get_contents($path);
        self::assertIsString($text);
        self::assertStringContainsString('To: ada@example.com', $text);
        self::assertStringContainsString('Subject: Hello', $text);
        if (is_file($path) && !unlink($path)) {
            self::fail('the mail file could not be removed');
        }

        $this->withEnv(['STALL_MAIL_FILE' => sys_get_temp_dir()], static function () use ($mail): void {
            $mail->send('ada@example.com', 'Hello', 'Body');
        });
        self::assertSame(['mail file write failed'], $log->errors);
    }

    public function testSmtpSettingsThatCannotSendAreLogged(): void
    {
        $log = new RecordedMailLog();
        $mail = new OutboundMail($log);
        $this->withEnv($this->clearedSmtp(), static function () use ($mail): void {
            $mail->send('ada@example.com', 'Hello', 'Body');
        });
        $this->withEnv($this->smtp('bad host', '587'), static function () use ($mail): void {
            $mail->send('ada@example.com', 'Hello', 'Body');
        });
        $this->withEnv($this->smtp('127.0.0.1', '0'), static function () use ($mail): void {
            $mail->send('ada@example.com', 'Hello', 'Body');
        });
        $this->withEnv($this->smtp('127.0.0.1', 'nope'), static function () use ($mail): void {
            $mail->send('ada@example.com', 'Hello', 'Body');
        });
        $this->withEnv($this->smtp('127.0.0.1', '587', 'not-an-email'), static function () use ($mail): void {
            $mail->send('ada@example.com', 'Hello', 'Body');
        });

        self::assertSame([
            'smtp is not configured',
            'smtp is not configured',
            'smtp is not configured',
            'smtp is not configured',
            'smtp is not configured',
        ], $log->errors);
    }

    public function testALocalSmtpDialogueThatRefusesTheMessageIsLogged(): void
    {
        $log = new RecordedMailLog();
        $mail = new OutboundMail($log);
        $server = $this->server('reject');
        try {
            $this->withEnv($this->smtp('127.0.0.1', (string) $server['port']), static function () use ($mail): void {
                $mail->send('ada@example.com', 'Hello', 'Body');
            });
        } finally {
            $this->stop($server);
        }

        self::assertSame(['smtp send failed'], $log->errors);
    }

    public function testTheClientStopsWhenTheServerRejectsACommand(): void
    {
        $server = $this->server('starttls');
        $client = new SmtpClient('127.0.0.1', $server['port'], 'user', 'secret', 'mall@example.com');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('smtp send failed');
            $client->send('ada@example.com', 'Hello', "Line\n.");
        } finally {
            $this->stop($server);
        }
    }

    public function testAFailedStartTlsIsRefused(): void
    {
        $server = $this->server('accept');
        $client = new SmtpClient(
            '127.0.0.1',
            $server['port'],
            'user',
            'secret',
            'mall@example.com',
            static fn (mixed $socket): bool => false
        );
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('smtp send failed');
            $client->send('ada@example.com', 'Hello', 'Body');
        } finally {
            $this->stop($server);
        }
    }

    public function testACompletedDialogueIsAccepted(): void
    {
        $server = $this->server('accept');
        $client = new SmtpClient(
            '127.0.0.1',
            $server['port'],
            'user',
            'secret',
            'mall@example.com',
            static fn (mixed $socket): bool => true
        );
        try {
            $client->send('ada@example.com', 'Hello', "Line\n.");
            self::assertTrue(true);
        } finally {
            $this->stop($server);
        }
    }

    public function testFortyContinuationLinesAreRefused(): void
    {
        $server = $this->server('long');
        $client = new SmtpClient(
            '127.0.0.1',
            $server['port'],
            'user',
            'secret',
            'mall@example.com',
            static fn (mixed $socket): bool => true
        );
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('smtp send failed');
            $client->send('ada@example.com', 'Hello', 'Body');
        } finally {
            $this->stop($server);
        }
    }

    public function testAMissingPieceIsNotConfigured(): void
    {
        $mail = new OutboundMail(new RecordedMailLog());
        $settings = new \ReflectionMethod(OutboundMail::class, 'settings');
        foreach (['STALL_SMTP_USERNAME', 'STALL_SMTP_PASSWORD', 'STALL_SMTP_FROM'] as $blank) {
            $env = $this->smtp('127.0.0.1', '587');
            $env[$blank] = '';
            $this->withEnv($env, function () use ($mail, $settings): void {
                self::assertNull($settings->invoke($mail));
            });
        }
        $port = new \ReflectionMethod(OutboundMail::class, 'port');
        $this->withEnv($this->smtp('127.0.0.1', '1'), function () use ($mail, $port): void {
            self::assertSame(1, $port->invoke($mail));
        });
        $this->withEnv($this->smtp('127.0.0.1', '65535'), function () use ($mail, $port): void {
            self::assertSame(65535, $port->invoke($mail));
        });
        $this->withEnv($this->smtp('127.0.0.1', '65536'), function () use ($mail, $port): void {
            self::assertNull($port->invoke($mail));
        });
        $unset = $this->smtp('127.0.0.1', '587');
        $unset['STALL_SMTP_PORT'] = null;
        $this->withEnv($unset, function () use ($mail, $port): void {
            self::assertSame(587, $port->invoke($mail));
        });
    }

    public function testTheClientStopsOnABrokenGreeting(): void
    {
        foreach (['garbage', 'tab', 'flood'] as $mode) {
            $server = $this->server($mode);
            $client = new SmtpClient('127.0.0.1', $server['port'], 'user', 'secret', 'mall@example.com');
            try {
                $client->send('ada@example.com', 'Hello', 'Body');
                self::fail('a broken greeting was accepted');
            } catch (\RuntimeException $error) {
                self::assertSame('smtp send failed', $error->getMessage());
            } finally {
                $this->stop($server);
            }
        }
    }

    /**
     * @return array{port: int, process: resource}
     */
    private function server(string $mode): array
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-smtp-stub.php';
        $written = file_put_contents($path, $this->serverProgram());
        if ($written === false) {
            self::fail('the mail server could not be written');
        }
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $path, $mode],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process) || !isset($pipes[1]) || !is_resource($pipes[1])) {
            self::fail('the mail server did not start');
        }
        $line = fgets($pipes[1]);
        if (!is_string($line) || preg_match('/:(\d+)/', $line, $matched) !== 1) {
            $this->stop(['port' => 0, 'process' => $process]);
            self::fail('the mail server has no port');
        }

        return ['port' => (int) $matched[1], 'process' => $process];
    }

    /**
     * @param array{port: int, process: resource} $server
     */
    private function stop(array $server): void
    {
        proc_close($server['process']);
    }

    private function serverProgram(): string
    {
        return <<<'PHP'
<?php
$mode = $argv[1] ?? '';
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, $error);
    exit(1);
}
$name = stream_socket_get_name($server, false);
fwrite(STDOUT, $name . "\n");
fflush(STDOUT);
$connection = stream_socket_accept($server, 5);
if (!is_resource($connection)) {
    exit(1);
}
if ($mode === 'reject') {
    fwrite($connection, "500 no\r\n");
    exit(0);
}
if ($mode === 'garbage') {
    fwrite($connection, "hello\r\n");
    exit(0);
}
if ($mode === 'tab') {
    fwrite($connection, "220\tnope\r\n");
    exit(0);
}
if ($mode === 'flood') {
    for ($line = 0; $line < 40; $line++) {
        fwrite($connection, "220-more\r\n");
    }
    exit(0);
}
if ($mode === 'accept' || $mode === 'long') {
    if ($mode === 'long') {
        for ($line = 0; $line < 40; $line++) {
            fwrite($connection, "220-more\r\n");
        }
        fwrite($connection, "220 ready\r\n");
    } else {
        fwrite($connection, "220-ready\r\n220 ready\r\n");
    }
    $phase = 'command';
    while (is_string($line = fgets($connection))) {
        if ($phase === 'user') {
            fwrite($connection, "334 next\r\n");
            $phase = 'pass';
            continue;
        }
        if ($phase === 'pass') {
            fwrite($connection, "235 ok\r\n");
            $phase = 'command';
            continue;
        }
        $text = strtoupper(trim($line));
        if (str_starts_with($text, 'DATA')) {
            fwrite($connection, "354 go\r\n");
            while (is_string($body = fgets($connection)) && trim($body) !== '.') {
            }
            fwrite($connection, "250 queued\r\n");
            continue;
        }
        if (str_starts_with($text, 'QUIT')) {
            fwrite($connection, "221 bye\r\n");
            break;
        }
        if (str_starts_with($text, 'AUTH')) {
            fwrite($connection, "334 next\r\n");
            $phase = 'user';
            continue;
        }
        if (str_starts_with($text, 'STARTTLS')) {
            fwrite($connection, "220 go\r\n");
            continue;
        }
        fwrite($connection, "250 OK\r\n");
    }
    exit(0);
}
fwrite($connection, "220-ready\r\n220 ready\r\n");
fgets($connection);
fwrite($connection, "250-PIPELINING\r\n250 OK\r\n");
fgets($connection);
fwrite($connection, "500 no\r\n");
PHP;
    }

    /**
     * @param array<string, string|null> $values
     */
    private function withEnv(array $values, callable $work): void
    {
        $previous = [];
        foreach ($values as $name => $value) {
            $current = getenv($name);
            $previous[$name] = is_string($current) ? $current : null;
            if ($value === null) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        try {
            $work();
        } finally {
            foreach ($previous as $name => $value) {
                if ($value === null) {
                    putenv($name);
                } else {
                    putenv($name . '=' . $value);
                }
            }
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function clearedSmtp(): array
    {
        return [
            'STALL_MAIL_FILE' => null,
            'STALL_SMTP_HOST' => null,
            'STALL_SMTP_PORT' => null,
            'STALL_SMTP_USERNAME' => null,
            'STALL_SMTP_PASSWORD' => null,
            'STALL_SMTP_FROM' => null,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function smtp(string $host, string $port, string $from = 'mall@example.com'): array
    {
        return [
            'STALL_MAIL_FILE' => null,
            'STALL_SMTP_HOST' => $host,
            'STALL_SMTP_PORT' => $port,
            'STALL_SMTP_USERNAME' => 'user',
            'STALL_SMTP_PASSWORD' => 'secret',
            'STALL_SMTP_FROM' => $from,
        ];
    }
}

final class RecordedMailLog implements LoggerInterface
{
    /** @var list<string> */
    public array $errors = [];

    public function emergency(\Stringable|string $message, array $context = []): void
    {
    }

    public function alert(\Stringable|string $message, array $context = []): void
    {
    }

    public function critical(\Stringable|string $message, array $context = []): void
    {
    }

    public function error(\Stringable|string $message, array $context = []): void
    {
        $this->errors[] = (string) $message;
    }

    public function warning(\Stringable|string $message, array $context = []): void
    {
    }

    public function notice(\Stringable|string $message, array $context = []): void
    {
    }

    public function info(\Stringable|string $message, array $context = []): void
    {
    }

    public function debug(\Stringable|string $message, array $context = []): void
    {
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
    }
}
