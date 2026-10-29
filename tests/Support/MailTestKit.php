<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Feature\Mall\ReceiptMailSettings;
use PerfectConsign\Feature\Password\ResetLetter;
use PerfectConsign\Feature\Password\ResetTokens;
use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PerfectConsign\Shared\Database\Statements;
use PDO;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class MailTestKit
{
    public static function resetLetter(PDO $pdo, LoggerInterface $log): ResetLetter
    {
        $statements = new Statements($pdo);
        $mail = new OutboundMail($log);
        $notices = new PlatformOperatorNotice($log);

        return new ResetLetter(
            $pdo,
            new DatabaseRole($pdo),
            new ResetTokens($pdo),
            new LetterMailbox(new ReceiptMailSettings($statements), $mail, new GmailSender($notices)),
            $log,
        );
    }

    public static function letterMailbox(Statements $statements, OutboundMail $mail, ?LoggerInterface $log = null): LetterMailbox
    {
        $log ??= new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
            }
        };
        $notices = new PlatformOperatorNotice($log);

        return new LetterMailbox(
            new ReceiptMailSettings($statements),
            $mail,
            new GmailSender($notices),
        );
    }
}
