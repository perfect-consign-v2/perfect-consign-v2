<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;
use PerfectConsign\Feature\Mall\MallGuard;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Mail\MailBounce;
use PerfectConsign\Shared\Mail\MailProgress;
use PerfectConsign\Shared\Mail\OutboundMail;
use Psr\Log\LoggerInterface;

final class DigestJob
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly OutboundMail $mail,
        private readonly MailBounce $bounce,
        private readonly LoggerInterface $logger,
        private readonly MailProgress $progress,
    ) {
    }

    public function digest(string $tenant, string $date): void
    {
        unset($tenant);
        if (!$this->weeklyMailOn()) {
            return;
        }
        $this->letters()->send('vendor-digest', $date, 'This week', 0);
    }

    public function remind(string $tenant, string $date): void
    {
        unset($tenant);
        $this->letters()->send('rent-reminder', $date, 'Rent is due', 1);
    }

    private function letters(): DigestLetters
    {
        return new DigestLetters($this->pdo, $this->mail, $this->bounce, $this->logger, $this->progress);
    }

    private function weeklyMailOn(): bool
    {
        $rows = (new Statements($this->pdo))->rows('SELECT vendor_weekly_mail_enabled FROM mall');
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new \RuntimeException('the mall has no clock');
        }

        return MallGuard::flag($row['vendor_weekly_mail_enabled'] ?? null);
    }
}
