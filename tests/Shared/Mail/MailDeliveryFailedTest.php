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
}
