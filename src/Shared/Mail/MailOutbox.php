<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

use InvalidArgumentException;
use PDO;
use PerfectConsign\Shared\Scalar\RowValue;
use RuntimeException;

final class MailOutbox
{
    public const MAILBOX_MALL = 'mall';

    public const MAILBOX_PLATFORM = 'platform';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function file(
        string $tenant,
        string $mailbox,
        string $purpose,
        string $address,
        string $subject,
        string $body,
    ): string {
        $this->assertTenant($tenant);
        $this->assertMailbox($mailbox);
        $purpose = $this->normalizedPurpose($purpose);
        $address = $this->normalizedAddress($address);
        $subject = $this->normalizedSubject($subject);
        $this->assertBody($body);
        $statement = $this->pdo->prepare(
            'SELECT platform.file_outbound_mail(?::text, ?::text, ?::text, ?::text, ?::text, ?::text)'
        );
        $statement->execute([$tenant, $mailbox, $purpose, $address, $subject, $body]);
        $id = $statement->fetchColumn();
        if (!is_string($id) || preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new RuntimeException('the mail could not be filed');
        }

        return strtolower($id);
    }

    /**
     * @return array{
     *     id: string,
     *     tenant: string,
     *     mailbox: string,
     *     purpose: string,
     *     address: string,
     *     subject: string,
     *     body: string,
     *     sent_at: string
     * }|null
     */
    public function open(string $id): ?array
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, tenant, mailbox, purpose, address, subject, body,
                    COALESCE(sent_at::text, \'\') AS sent_at
             FROM platform.mail_outbox
             WHERE id = ?::uuid'
        );
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => RowValue::string($row['id'] ?? null),
            'tenant' => RowValue::string($row['tenant'] ?? null),
            'mailbox' => RowValue::string($row['mailbox'] ?? null),
            'purpose' => RowValue::string($row['purpose'] ?? null),
            'address' => RowValue::string($row['address'] ?? null),
            'subject' => RowValue::string($row['subject'] ?? null),
            'body' => RowValue::string($row['body'] ?? null),
            'sent_at' => RowValue::string($row['sent_at'] ?? null),
        ];
    }

    public function markSent(string $id): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1) {
            throw new InvalidArgumentException('the mail is missing');
        }
        $statement = $this->pdo->prepare(
            'UPDATE platform.mail_outbox
             SET sent_at = clock_timestamp()
             WHERE id = ?::uuid AND sent_at IS NULL'
        );
        $statement->execute([$id]);
    }

    private function assertTenant(string $tenant): void
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $tenant) !== 1) {
            throw new InvalidArgumentException('the mall is not a mall');
        }
    }

    private function assertMailbox(string $mailbox): void
    {
        if ($mailbox !== self::MAILBOX_MALL && $mailbox !== self::MAILBOX_PLATFORM) {
            throw new InvalidArgumentException('the mailbox is not a mailbox');
        }
    }

    private function normalizedPurpose(string $purpose): string
    {
        $purpose = trim($purpose);
        if ($purpose === '' || strlen($purpose) > 64) {
            throw new InvalidArgumentException('the mail purpose is missing');
        }

        return $purpose;
    }

    private function normalizedAddress(string $address): string
    {
        $address = strtolower(trim($address));
        if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $address) !== 1 || strlen($address) > 200) {
            throw new InvalidArgumentException('that email is not an email address');
        }
        if (str_contains($address, "\n") || str_contains($address, "\r")) {
            throw new InvalidArgumentException('that email is not an email address');
        }

        return $address;
    }

    private function normalizedSubject(string $subject): string
    {
        $subject = trim($subject);
        if ($subject === '' || strlen($subject) > 200 || str_contains($subject, "\n") || str_contains($subject, "\r")) {
            throw new InvalidArgumentException('the subject is missing');
        }

        return $subject;
    }

    private function assertBody(string $body): void
    {
        if ($body === '' || strlen($body) > 20000) {
            throw new InvalidArgumentException('the message is missing');
        }
    }
}
