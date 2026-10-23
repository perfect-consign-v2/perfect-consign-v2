<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

use PerfectConsign\Shared\Database\Statements;

final class MailBounce
{
    public function __construct(private readonly Statements $statements)
    {
    }

    public function mark(string $email): void
    {
        $this->statements->change(
            'UPDATE vendors SET email_bounced = true WHERE lower(email) = lower(?)',
            [$email]
        );
    }
}
