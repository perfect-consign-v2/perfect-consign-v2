<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Mail;

use PerfectConsign\Feature\Mall\ReceiptMailSettings;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PerfectConsign\Shared\Mail\SealedSecret;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class LetterMailboxTest extends TestCase
{
    public function testCapturedMailIsReadyWithoutGmailWhenViaServer(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pc-letter-ready-');
        if ($path === false) {
            self::fail('the mail file could not be created');
        }
        $previous = getenv('STALL_MAIL_FILE');
        putenv('STALL_MAIL_FILE=' . $path);
        try {
            $statements = $this->createStub(Statements::class);
            $statements->method('rows')->willReturn([
                ['receipt_mail_via' => 'server', 'gmail_address' => null, 'gmail_app_password' => null],
            ]);
            $mail = new OutboundMail($this->silentLog());
            $mailbox = new LetterMailbox(
                new ReceiptMailSettings($statements),
                $mail,
                new GmailSender(new PlatformOperatorNotice($this->silentLog())),
            );
            self::assertTrue($mailbox->ready());
        } finally {
            if (is_string($previous) && $previous !== '') {
                putenv('STALL_MAIL_FILE=' . $previous);
            } else {
                putenv('STALL_MAIL_FILE');
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testCapturedGmailViaWithPasswordButNoAddressIsNotReady(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pc-letter-gmail-');
        if ($path === false) {
            self::fail('the mail file could not be created');
        }
        $previous = getenv('STALL_MAIL_FILE');
        putenv('STALL_MAIL_FILE=' . $path);
        try {
            $statements = $this->createStub(Statements::class);
            $statements->method('rows')->willReturn([
                [
                    'receipt_mail_via' => 'gmail',
                    'gmail_address' => '',
                    'gmail_app_password' => SealedSecret::seal('abcdefghijklmnop'),
                ],
            ]);
            $mail = new OutboundMail($this->silentLog());
            $mailbox = new LetterMailbox(
                new ReceiptMailSettings($statements),
                $mail,
                new GmailSender(new PlatformOperatorNotice($this->silentLog())),
            );
            self::assertFalse($mailbox->ready());
        } finally {
            if (is_string($previous) && $previous !== '') {
                putenv('STALL_MAIL_FILE=' . $previous);
            } else {
                putenv('STALL_MAIL_FILE');
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testUncapturedGmailViaRequiresGmailAccount(): void
    {
        putenv('STALL_MAIL_FILE');
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturn([
            [
                'receipt_mail_via' => 'gmail',
                'gmail_address' => 'shop@example.test',
                'gmail_app_password' => null,
            ],
        ]);
        $mail = new OutboundMail($this->silentLog());
        $mailbox = new LetterMailbox(
            new ReceiptMailSettings($statements),
            $mail,
            new GmailSender(new PlatformOperatorNotice($this->silentLog())),
        );
        self::assertFalse($mailbox->ready());
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
