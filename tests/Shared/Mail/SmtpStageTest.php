<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Mail;

use PerfectConsign\Shared\Mail\MailDeliveryFailed;
use PerfectConsign\Shared\Mail\SmtpClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SmtpStageTest extends TestCase
{
    public function testALoginFailureIsNotARecipientBounce(): void
    {
        $failed = $this->stage('auth', 18771);
        self::assertSame(535, $failed->status());
        self::assertSame('auth', $failed->stage());
        self::assertFalse($failed->permanent());
    }

    public function testARecipientRejectionIsABounce(): void
    {
        $failed = $this->stage('rcpt', 18772);
        self::assertSame(550, $failed->status());
        self::assertSame('rcpt', $failed->stage());
        self::assertTrue($failed->permanent());
    }

    public function testAFourHundredGreetingIsADeliveryFailure(): void
    {
        $failed = $this->stage('greeting', 18774);
        self::assertSame(400, $failed->status());
        self::assertSame('greeting', $failed->stage());
    }

    public function testATemporaryRecipientFailureIsRetried(): void
    {
        $failed = $this->stage('later', 18773);
        self::assertSame(421, $failed->status());
        self::assertSame('rcpt', $failed->stage());
        self::assertFalse($failed->permanent());
    }

    public function testMessageIdDomainComesFromFromAddress(): void
    {
        $payload = new ReflectionMethod(SmtpClient::class, 'payload');
        $client = new SmtpClient('127.0.0.1', 587, 'shop@example.test', 'secret', 'shop@example.test');
        $text = $payload->invoke($client, 'shop@example.test', 'ada@example.test', 'Sold', "line\n");
        self::assertIsString($text);
        self::assertMatchesRegularExpression('/Message-ID: <[0-9a-f]{32}@example\.test>/', $text);

        $fallback = new SmtpClient('127.0.0.1', 587, 'shop', 'secret', 'shop');
        $plain = $payload->invoke($fallback, 'shop', 'ada@example.test', 'Sold', 'line');
        self::assertIsString($plain);
        self::assertMatchesRegularExpression('/Message-ID: <[0-9a-f]{32}@localhost>/', $plain);
    }

    private function stage(string $mode, int $port): MailDeliveryFailed
    {
        $command = [PHP_BINARY, dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'fake-smtp.php', $mode, (string) $port];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            self::fail('the smtp stand-in did not start');
        }
        usleep(200000);
        try {
            $client = new SmtpClient('127.0.0.1', $port, 'shop@example.test', 'secret', 'shop@example.test', static fn (mixed $socket): bool => true);
            $client->send('ada@example.test', 'Sold today', 'one line');
            self::fail('the smtp stand-in accepted the message');
        } catch (MailDeliveryFailed $failed) {
            return $failed;
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }
    }
}
