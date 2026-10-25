<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

use PerfectConsign\Shared\Database\Statements;

final class MailProgress
{
    public function sent(Statements $statements, string $kind, string $date, string $email): bool
    {
        $rows = $statements->bound(
            'SELECT 1 AS sent FROM job_mail_sent
             WHERE kind = ? AND mail_date = ?::date AND email = ?',
            [$kind, $date, $email]
        );

        return $rows !== [];
    }

    public function record(Statements $statements, string $kind, string $date, string $email): void
    {
        $statements->change(
            'INSERT INTO job_mail_sent (kind, mail_date, email)
             VALUES (?, ?::date, ?)
             ON CONFLICT (kind, mail_date, email) DO NOTHING',
            [$kind, $date, $email]
        );
    }
}
