<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;
use PerfectConsign\Feature\Vendor\VendorFigures;
use PerfectConsign\Feature\Vendor\VendorLetter;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Mail\MailAttempt;
use PerfectConsign\Shared\Mail\MailBounce;
use PerfectConsign\Shared\Mail\MailProgress;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Scalar\RowValue;
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
        $this->send($tenant, 'vendor-digest', $date, 'This week', 0);
    }

    public function remind(string $tenant, string $date): void
    {
        $this->send($tenant, 'rent-reminder', $date, 'Rent is due', 1);
    }

    private function send(string $tenant, string $kind, string $date, string $subject, int $minimumRent): void
    {
        $statements = new Statements($this->pdo);
        $figures = new VendorFigures($statements);
        $vendors = $statements->rows(
            "SELECT id, email, name FROM vendors
             WHERE active = true AND email IS NOT NULL AND email <> '' AND NOT email_bounced"
        );
        $waiting = false;
        foreach ($vendors as $vendor) {
            $email = RowValue::optionalString($vendor['email'] ?? null);
            if ($email === null || $this->progress->sent($statements, $tenant, $kind, $date, $email)) {
                continue;
            }
            $numbers = $figures->numbers(RowValue::string($vendor['id'] ?? null));
            if ($numbers['rent'] < $minimumRent) {
                continue;
            }
            try {
                MailAttempt::send(
                    $this->mail,
                    $this->bounce,
                    $this->logger,
                    $email,
                    $subject,
                    VendorLetter::body(RowValue::string($vendor['name'] ?? null), $numbers['payable'], $numbers['rent'])
                );
            } catch (\RuntimeException $failed) {
                $this->logger->error('a letter is still waiting', ['email' => $email, 'error' => $failed->getMessage()]);
                $waiting = true;
                continue;
            }
            $this->progress->record($statements, $tenant, $kind, $date, $email);
        }
        if ($waiting) {
            throw new \RuntimeException('a letter is still waiting to be sent');
        }
    }
}
