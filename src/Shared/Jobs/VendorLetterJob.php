<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Shared\Mail\MailBounce;
use PerfectConsign\Shared\Mail\MailDeliveryFailed;
use PerfectConsign\Shared\Scalar\RowValue;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class VendorLetterJob
{
    public function __construct(
        private readonly Statements $statements,
        private readonly LetterMailbox $mailbox,
        private readonly MailBounce $bounce,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(string $letterId): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $letterId) !== 1) {
            throw new RuntimeException('a letter is missing');
        }
        $rows = $this->statements->bound(
            'SELECT subject, body FROM vendor_letters WHERE id = ?::uuid',
            [$letterId]
        );
        $letter = $rows[0] ?? null;
        if (!is_array($letter)) {
            throw new RuntimeException('a letter is missing');
        }
        $subject = RowValue::string($letter['subject'] ?? null);
        $body = RowValue::string($letter['body'] ?? null);
        $copies = $this->statements->bound(
            'SELECT copy.vendor_id::text AS vendor_id, copy.email
             FROM vendor_letter_copies AS copy
             JOIN vendors AS vendor ON vendor.id = copy.vendor_id
             WHERE copy.letter_id = ?::uuid
               AND copy.sent_at IS NULL
               AND NOT vendor.email_bounced',
            [$letterId]
        );
        foreach ($copies as $copy) {
            $email = RowValue::string($copy['email'] ?? null);
            $vendorId = RowValue::string($copy['vendor_id'] ?? null);
            if (!$this->deliver($email, $subject, $body)) {
                continue;
            }
            $this->statements->change(
                'UPDATE vendor_letter_copies SET sent_at = clock_timestamp()
                 WHERE letter_id = ?::uuid AND vendor_id = ?::uuid AND sent_at IS NULL',
                [$letterId, $vendorId]
            );
        }
    }

    private function deliver(string $email, string $subject, string $body): bool
    {
        try {
            $this->mailbox->send($email, $subject, $body);
        } catch (MailDeliveryFailed $failed) {
            $this->logger->error('mail was not delivered', ['status' => $failed->status(), 'stage' => $failed->stage()]);
            if ($failed->permanent()) {
                $this->bounce->mark($email);

                return false;
            }
            throw $failed;
        }

        return true;
    }
}
