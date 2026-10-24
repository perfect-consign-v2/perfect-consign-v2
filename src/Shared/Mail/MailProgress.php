<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

use PerfectConsign\Shared\Database\Statements;

final class MailProgress
{
    public function sent(Statements $statements, string $tenant, string $kind, string $date, string $email): bool
    {
        $rows = $statements->bound(
            'SELECT 1 AS sent FROM platform.job_mail_sent
             WHERE tenant = ? AND kind = ? AND mail_date = ?::date AND email = ?',
            [$tenant, $kind, $date, $email]
        );

        return $rows !== [];
    }

    public function record(Statements $statements, string $tenant, string $kind, string $date, string $email): void
    {
        $statements->change(
            'INSERT INTO platform.job_mail_sent (tenant, kind, mail_date, email)
             VALUES (?, ?, ?::date, ?)
             ON CONFLICT (tenant, kind, mail_date, email) DO NOTHING',
            [$tenant, $kind, $date, $email]
        );
    }
}
