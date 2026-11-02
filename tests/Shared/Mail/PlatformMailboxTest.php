<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Mail;

use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformMailbox;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class PlatformMailboxTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $previous = [];

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        $this->previous = [];
    }

    public function testReadyWhenMailIsCapturedOrConfigured(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pc-platform-ready-');
        if ($path === false) {
            self::fail('the mail file could not be created');
        }
        $this->setEnv('STALL_MAIL_FILE', $path);
        try {
            $log = $this->silentLog();
            $mailbox = new PlatformMailbox(
                new OutboundMail($log),
                new GmailSender(new PlatformOperatorNotice($log)),
                new PlatformOperatorNotice($log),
            );
            self::assertTrue($mailbox->ready());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testAccountRequiresAddressAndPassword(): void
    {
        putenv('STALL_GMAIL_ADDRESS');
        putenv('STALL_GMAIL_APP_PASSWORD');
        $log = $this->silentLog();
        $mailbox = new PlatformMailbox(
            new OutboundMail($log),
            new GmailSender(new PlatformOperatorNotice($log)),
            new PlatformOperatorNotice($log),
        );
        self::assertNull($mailbox->account());

        $this->setEnv('STALL_GMAIL_ADDRESS', 'ops@example.com');
        putenv('STALL_GMAIL_APP_PASSWORD');
        self::assertNull($mailbox->account());

        $this->setEnv('STALL_GMAIL_APP_PASSWORD', '   ');
        self::assertNull($mailbox->account());
    }

    public function testAccountStripsSpacesFromAppPassword(): void
    {
        $this->setEnv('STALL_GMAIL_ADDRESS', 'ops@example.com');
        $this->setEnv('STALL_GMAIL_APP_PASSWORD', 'abcd efgh');
        $log = $this->silentLog();
        $mailbox = new PlatformMailbox(
            new OutboundMail($log),
            new GmailSender(new PlatformOperatorNotice($log)),
            new PlatformOperatorNotice($log),
        );
        $account = $mailbox->account();
        self::assertIsArray($account);
        self::assertSame('abcdefgh', $account['password']);
    }

    public function testMailFileForcesDeliverPathEvenWithGmailEnv(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pc-platform-send-');
        if ($path === false) {
            self::fail('the mail file could not be created');
        }
        $this->setEnv('STALL_MAIL_FILE', $path);
        $this->setEnv('STALL_GMAIL_ADDRESS', 'ops@example.com');
        $this->setEnv('STALL_GMAIL_APP_PASSWORD', 'secret');
        $log = $this->silentLog();
        $mailbox = new PlatformMailbox(
            new OutboundMail($log),
            new GmailSender(new PlatformOperatorNotice($log)),
            new PlatformOperatorNotice($log),
        );
        $mailbox->send('ada@example.test', 'Subject', "Body\n", 'test purpose');
        $text = file_get_contents($path);
        self::assertIsString($text);
        self::assertStringContainsString('To: ada@example.test', $text);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->previous)) {
            $this->previous[$key] = getenv($key);
        }
        putenv($key . '=' . $value);
    }

    private function silentLog(): AbstractLogger
    {
        return new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
            }
        };
    }
}
