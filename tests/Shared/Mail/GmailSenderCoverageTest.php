<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Mail;

use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\MailDeliveryFailed;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GmailSenderCoverageTest extends TestCase
{
    public function testARefusedGreetingIsReportedAndRethrown(): void
    {
        $logged = [];
        $stand = $this->listen('refuse');
        $admin = getenv('STALL_PLATFORM_ADMIN_EMAIL');
        putenv('STALL_PLATFORM_ADMIN_EMAIL');
        try {
            $sender = new GmailSender(new PlatformOperatorNotice($this->logger($logged)));
            $sender->send(
                $this->account($stand['port']),
                'buyer@mall.test',
                'Receipt 41',
                'Hello',
                'receipt mail was refused',
            );
            self::fail('a refused greeting was delivered');
        } catch (MailDeliveryFailed $failed) {
            self::assertSame(550, $failed->status());
            self::assertSame('greeting', $failed->stage());
        } finally {
            $this->stop($stand);
            $this->restoreAdmin($admin);
        }

        $this->assertNotice($logged);
    }

    public function testABrokenGreetingBecomesASendFailure(): void
    {
        $logged = [];
        $stand = $this->listen('break');
        $admin = getenv('STALL_PLATFORM_ADMIN_EMAIL');
        putenv('STALL_PLATFORM_ADMIN_EMAIL');
        try {
            $sender = new GmailSender(new PlatformOperatorNotice($this->logger($logged)));
            $sender->send(
                $this->account($stand['port']),
                'buyer@mall.test',
                'Receipt 41',
                'Hello',
                'receipt mail was refused',
            );
            self::fail('a broken greeting was delivered');
        } catch (\RuntimeException $error) {
            self::assertSame('smtp send failed', $error->getMessage());
            self::assertNotInstanceOf(MailDeliveryFailed::class, $error->getPrevious());
        } finally {
            $this->stop($stand);
            $this->restoreAdmin($admin);
        }

        $this->assertNotice($logged);
    }

    /**
     * @param list<array{message: string, context: array<mixed>}> $logged
     */
    private function assertNotice(array $logged): void
    {
        self::assertNotSame([], $logged);
        $context = $logged[0]['context'];
        self::assertIsArray($context);
        self::assertSame('receipt mail was refused', $logged[0]['message']);
        self::assertSame('ada@gmail.com', $context['from'] ?? null);
        $encoded = json_encode($context);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('app-password-value', $encoded);
    }

    /**
     * @return array{host: string, port: int, username: string, password: string, from: string}
     */
    private function account(int $port): array
    {
        return [
            'host' => '127.0.0.1',
            'port' => $port,
            'username' => 'ada@gmail.com',
            'password' => 'app-password-value',
            'from' => 'ada@gmail.com',
        ];
    }

    /**
     * @param list<array{message: string, context: array<mixed>}> $logged
     */
    private function logger(array &$logged): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(
            static function (string|\Stringable $message, array $context) use (&$logged): void {
                $logged[] = ['message' => (string) $message, 'context' => $context];
            }
        );

        return $logger;
    }

    private function restoreAdmin(string|false $admin): void
    {
        if (is_string($admin) && $admin !== '') {
            putenv('STALL_PLATFORM_ADMIN_EMAIL=' . $admin);

            return;
        }
        putenv('STALL_PLATFORM_ADMIN_EMAIL');
    }

    /**
     * @return array{process: resource, pipes: list<resource>, files: list<string>, port: int}
     */
    private function listen(string $mode): array
    {
        $script = tempnam(sys_get_temp_dir(), 'pc2smtp');
        $portFile = tempnam(sys_get_temp_dir(), 'pc2port');
        if ($script === false || $portFile === false) {
            self::fail('the smtp stand-in files could not be created');
        }
        $written = file_put_contents($script, $this->listener());
        if ($written === false) {
            self::fail('the smtp stand-in could not be written');
        }
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $script, $portFile, $mode],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if ($process === false) {
            self::fail('the smtp stand-in did not start');
        }
        $opened = [];
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                $opened[] = $pipe;
            }
        }

        return [
            'process' => $process,
            'pipes' => $opened,
            'files' => [$script, $portFile],
            'port' => $this->port($portFile),
        ];
    }

    /**
     * @param array{process: resource, pipes: list<resource>, files: list<string>, port: int} $stand
     */
    private function stop(array $stand): void
    {
        foreach ($stand['pipes'] as $pipe) {
            fclose($pipe);
        }
        proc_close($stand['process']);
        foreach ($stand['files'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function port(string $file): int
    {
        $deadline = microtime(true) + 3;
        while (microtime(true) < $deadline) {
            $text = is_file($file) ? file_get_contents($file) : false;
            if (is_string($text) && preg_match('/:(\d+)$/', trim($text), $match) === 1) {
                return (int) $match[1];
            }
            usleep(20000);
        }
        self::fail('the smtp stand-in did not publish a port');
    }

    private function listener(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, $error);
    exit(1);
}
$name = stream_socket_get_name($server, false);
if (!is_string($name)) {
    exit(1);
}
$written = file_put_contents($argv[1], $name);
if ($written === false) {
    exit(1);
}
$client = stream_socket_accept($server, 5);
if ($client === false) {
    exit(1);
}
$line = ($argv[2] ?? '') === 'refuse' ? "550 refused\r\n" : "hello\r\n";
$sent = fwrite($client, $line);
if ($sent === false) {
    exit(1);
}
fclose($client);
fclose($server);
PHP;
    }
}
