<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;
use PerfectConsign\Feature\Vendor\VendorFigures;
use PerfectConsign\Feature\Vendor\VendorLetter;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Mail\MailAttempt;
use PerfectConsign\Shared\Mail\MailBounce;
use PerfectConsign\Shared\Mail\MailDeliveryFailed;
use PerfectConsign\Shared\Mail\MailProgress;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Scalar\RowValue;
use Psr\Log\LoggerInterface;

final class DigestLetters
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly OutboundMail $mail,
        private readonly MailBounce $bounce,
        private readonly LoggerInterface $logger,
        private readonly MailProgress $progress,
    ) {
    }

    public function send(string $kind, string $date, string $subject, int $minimumRent): void
    {
        $statements = new Statements($this->pdo);
        $figures = new VendorFigures($statements);
        $waiting = false;
        foreach ($this->vendors($statements) as $vendor) {
            $email = RowValue::optionalString($vendor['email'] ?? null);
            if ($email === null || $this->progress->sent($statements, $kind, $date, $email)) {
                continue;
            }
            $numbers = $figures->numbers(RowValue::string($vendor['id'] ?? null));
            if ($numbers['rent'] < $minimumRent) {
                continue;
            }
            if (!$this->deliver($statements, $kind, $date, $email, $subject, $vendor, $numbers)) {
                $waiting = true;
            }
        }
        if ($waiting) {
            throw new \RuntimeException('a letter is still waiting to be sent');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function vendors(Statements $statements): array
    {
        return $statements->rows(
            "SELECT vendor.id, stall_party_primary_email(vendor.party_id) AS email, vendor.name
             FROM vendors AS vendor
             WHERE vendor.active = true
               AND stall_party_primary_email(vendor.party_id) IS NOT NULL
               AND stall_party_primary_email(vendor.party_id) <> ''
               AND NOT vendor.email_bounced"
        );
    }

    /**
     * @param array<string, mixed> $vendor
     * @param array{payable: int, rent: int, clawback: int} $numbers
     */
    private function deliver(
        Statements $statements,
        string $kind,
        string $date,
        string $email,
        string $subject,
        array $vendor,
        array $numbers,
    ): bool {
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
            $this->logger->error('a letter is still waiting', MailDeliveryFailed::context($failed, [
                'email' => $email,
                'error' => $failed->getMessage(),
            ]));

            return false;
        }
        $this->progress->record($statements, $kind, $date, $email);

        return true;
    }
}
