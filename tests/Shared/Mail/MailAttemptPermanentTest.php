<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Mail;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Mail\MailAttempt;
use PerfectConsign\Shared\Mail\MailBounce;
use PerfectConsign\Shared\Mail\MailDeliveryFailed;
use PerfectConsign\Shared\Mail\OutboundMail;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MailAttemptPermanentTest extends TestCase
{
    public function testAPermanentRefusalIsMarkedAndATemporaryOneIsRethrown(): void
    {
        $logger = new NullLogger();
        $mail = new OutboundMail($logger);
        $bounce = new MailBounce(new class(new PDO('sqlite::memory:')) extends Statements {
            public function __construct(PDO $pdo)
            {
                parent::__construct($pdo);
            }

            public function change(string $sql, array $params): void
            {
            }
        });
        $mail->useDelivery(static function (): void {
            throw new MailDeliveryFailed(550, 'rcpt');
        });
        MailAttempt::send($mail, $bounce, $logger, 'ada@example.test', 'Hello', 'Body');

        $mail->useDelivery(static function (): void {
            throw new MailDeliveryFailed(450, 'rcpt');
        });
        try {
            MailAttempt::send($mail, $bounce, $logger, 'ada@example.test', 'Hello', 'Body');
            self::fail('a temporary refusal was kept');
        } catch (MailDeliveryFailed $error) {
            self::assertFalse($error->permanent());
        }
    }
}
