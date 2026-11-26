<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Signup;

use PerfectConsign\Shared\Mail\MailDeliveryFailed;
use PerfectConsign\Shared\Mail\PlatformMailbox;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The two letters a public signup sends from the web request: the link that
 * finishes setting up a new store, and the note to an email that already has one.
 * A failed send is logged and never changes what the visitor sees.
 */
final class SignupMail
{
    public function __construct(
        private readonly PlatformMailbox $mailbox,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function ready(): bool
    {
        return $this->mailbox->ready();
    }

    public function link(string $email, string $origin, string $token, int $hours): bool
    {
        $body = "Open this link to finish setting up your Perfect Consign store:\n"
            . $origin . '/signup/verify/' . $token . "\n\n"
            . 'The link works for ' . $hours . ' hours. Your store is built after you open it.' . "\n\n"
            . "If you did not ask for a store, ignore this email. Nothing has been set up.\n";

        return $this->send($email, 'Finish setting up your store', $body, 'signup link');
    }

    public function known(string $email, string $origin): bool
    {
        $body = "Someone asked to set up a new Perfect Consign store with this email address.\n"
            . "You already have a store, so nothing new was set up.\n\n"
            . "Sign in here:\n" . $origin . '/?email=' . rawurlencode($email) . "\n\n"
            . "Forgot your password? Reset it here:\n" . $origin . "/forgot\n\n"
            . "If this was not you, you can ignore this email.\n";

        return $this->send($email, 'You already have a store', $body, 'signup known email');
    }

    private function send(string $email, string $subject, string $body, string $purpose): bool
    {
        try {
            $this->mailbox->send($email, $subject, $body, $purpose . ' was refused');
        } catch (Throwable $error) {
            $this->logger->error($purpose . ' was refused', MailDeliveryFailed::context($error, [
                'message' => $error->getMessage(),
            ]));

            return false;
        }

        return true;
    }
}
