<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Mail;

use PerfectConsign\Shared\Mail\MailDeliveryFailed;
use PHPUnit\Framework\TestCase;

final class MailDeliveryFailedTest extends TestCase
{
    public function testOnlyAPermanentStatusIsABounce(): void
    {
        self::assertTrue((new MailDeliveryFailed(550, 'rcpt'))->permanent());
        self::assertFalse((new MailDeliveryFailed(535, 'auth'))->permanent());
        self::assertFalse((new MailDeliveryFailed(550, 'mail'))->permanent());
        self::assertFalse((new MailDeliveryFailed(421, 'rcpt'))->permanent());
        self::assertFalse((new MailDeliveryFailed(250, 'rcpt'))->permanent());
    }
}
