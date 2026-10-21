<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;
use PerfectConsign\Feature\Vendor\VendorFigures;
use PerfectConsign\Feature\Vendor\VendorLetter;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Scalar\RowValue;

final class DigestJob
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly OutboundMail $mail,
    ) {
    }

    public function digest(): void
    {
        $this->send('This week', 0);
    }

    public function remind(): void
    {
        $this->send('Rent is due', 1);
    }

    private function send(string $subject, int $minimumRent): void
    {
        $statements = new Statements($this->pdo);
        $figures = new VendorFigures($statements);
        $vendors = $statements->rows(
            "SELECT id, email, name FROM vendors WHERE active = true AND email IS NOT NULL AND email <> ''"
        );
        foreach ($vendors as $vendor) {
            $email = RowValue::optionalString($vendor['email'] ?? null);
            if ($email === null) {
                continue;
            }
            $numbers = $figures->numbers(RowValue::string($vendor['id'] ?? null));
            if ($numbers['rent'] < $minimumRent) {
                continue;
            }
            $this->mail->deliver(
                $email,
                $subject,
                VendorLetter::body(RowValue::string($vendor['name'] ?? null), $numbers['payable'], $numbers['rent'])
            );
        }
    }
}
