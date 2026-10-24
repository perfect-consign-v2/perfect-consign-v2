<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

use Psr\Log\LoggerInterface;

final class MailAttempt
{
    public static function send(
        OutboundMail $mail,
        MailBounce $bounce,
        LoggerInterface $logger,
        string $email,
        string $subject,
        string $body,
    ): void {
        try {
            $mail->deliver($email, $subject, $body);
        } catch (MailDeliveryFailed $failed) {
            $logger->error('mail was not delivered', ['status' => $failed->status(), 'stage' => $failed->stage()]);
            if ($failed->permanent()) {
                $bounce->mark($email);

                return;
            }
            throw $failed;
        }
    }
}
