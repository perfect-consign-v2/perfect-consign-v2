<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Mail;

use PerfectConsign\Shared\Mail\MailDeliveryFailed;
use PHPUnit\Framework\TestCase;

final class MailDeliveryFailedTest extends TestCase
{
    public function testOnlyARecipientFiveHundredIsPermanent(): void
    {
        self::assertTrue((new MailDeliveryFailed(500, 'rcpt'))->permanent());
        self::assertTrue((new MailDeliveryFailed(599, 'rcpt'))->permanent());
        self::assertFalse((new MailDeliveryFailed(499, 'rcpt'))->permanent());
        self::assertFalse((new MailDeliveryFailed(600, 'rcpt'))->permanent());
        self::assertFalse((new MailDeliveryFailed(550, 'mail'))->permanent());
    }

    public function testTheServerReplyIsLoggedWhenTheServerSentOne(): void
    {
        $plain = new MailDeliveryFailed(550, 'data');
        self::assertSame('', $plain->reply());
        self::assertSame(
            ['status' => 550, 'stage' => 'data'],
            MailDeliveryFailed::context($plain),
        );
        $failed = new MailDeliveryFailed(550, 'data', '5.4.5 Daily user sending limit exceeded.');
        self::assertSame(
            [
                'message' => 'smtp send failed',
                'status' => 550,
                'stage' => 'data',
                'reply' => '5.4.5 Daily user sending limit exceeded.',
            ],
            MailDeliveryFailed::context($failed, ['message' => 'smtp send failed']),
        );
        self::assertSame(
            ['message' => 'other'],
            MailDeliveryFailed::context(new \RuntimeException('other'), ['message' => 'other']),
        );
    }
}
