<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Jobs;

use PerfectConsign\Feature\Mall\ReceiptMailSettings;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Jobs\VendorLetterJob;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Shared\Mail\MailBounce;
use PerfectConsign\Shared\Mail\MailDeliveryFailed;
use PerfectConsign\Shared\Mail\OutboundMail;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class VendorLetterJobTest extends TestCase
{
    public function testALoginFailureDoesNotBounceTheAddress(): void
    {
        $statements = $this->createMock(Statements::class);
        $statements->expects($this->exactly(2))->method('bound')->willReturnOnConsecutiveCalls(
            [['subject' => 'Pickup', 'body' => 'Your items are ready.']],
            [['vendor_id' => '01900000-0000-7000-8000-00000000c101', 'email' => 'ada@example.test']],
        );
        $statements->expects($this->never())->method('change');
        $job = new VendorLetterJob(
            $statements,
            new LoginRefusalMailbox(new ReceiptMailSettings($this->createStub(Statements::class)), new OutboundMail($this->createStub(LoggerInterface::class))),
            new MailBounce($statements),
            $this->createStub(LoggerInterface::class),
        );

        $this->expectException(MailDeliveryFailed::class);
        $job->send('01900000-0000-7000-8000-00000000c201');
    }

    public function testACapturedLetterIsMarkedSent(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pc-letter-');
        if ($file === false) {
            self::fail('the mail file could not be created');
        }
        putenv('STALL_MAIL_FILE=' . $file);
        try {
            $statements = $this->createMock(Statements::class);
            $statements->method('rows')->willReturn([
                ['receipt_mail_via' => 'server', 'gmail_address' => null, 'gmail_app_password' => null],
            ]);
            $statements->expects($this->exactly(2))->method('bound')->willReturnOnConsecutiveCalls(
                [['subject' => 'Pickup', 'body' => 'Your items are ready.']],
                [['vendor_id' => '01900000-0000-7000-8000-00000000c101', 'email' => 'ada@example.test']],
            );
            $statements->expects($this->once())->method('change');
            $mail = new OutboundMail($this->createStub(LoggerInterface::class));
            self::assertTrue($mail->captured());
            $mailbox = new LetterMailbox(new ReceiptMailSettings($statements), $mail);
            self::assertTrue($mail->configured() || $mailbox->ready());
            $job = new VendorLetterJob(
                $statements,
                $mailbox,
                new MailBounce($statements),
                $this->createStub(LoggerInterface::class),
            );
            $job->send('01900000-0000-7000-8000-00000000c201');
            $written = file_get_contents($file);
            self::assertIsString($written);
            self::assertStringContainsString('To: ada@example.test', $written);
            self::assertStringContainsString('Subject: Pickup', $written);
        } finally {
            putenv('STALL_MAIL_FILE');
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}

final class LoginRefusalMailbox extends LetterMailbox
{
    public function send(string $to, string $subject, string $body): void
    {
        throw new MailDeliveryFailed(535, 'auth');
    }
}
