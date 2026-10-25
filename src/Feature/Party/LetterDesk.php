<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use InvalidArgumentException;
use PDO;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Jobs\JobQueue;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Shared\Scalar\RowValue;

final class LetterDesk
{
    public function __construct(
        private readonly Statements $statements,
        private readonly PDO $pdo,
        private readonly JobQueue $queue,
        private readonly LetterMailbox $mailbox,
        private readonly LetterDraft $draft,
    ) {
    }

    /**
     * @param list<string> $vendorIds
     */
    public function fileLetter(string $slug, string $date, string $subject, string $message, bool $everyone, array $vendorIds): string
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $slug) !== 1) {
            throw new InvalidArgumentException('the mall is not a mall');
        }
        $subject = $this->draft->subject($subject);
        $message = $this->draft->message($message);
        if (!$this->mailbox->ready()) {
            throw new InvalidArgumentException('Mail is not set up.');
        }
        $recipients = $everyone ? $this->eligible() : $this->chosen($vendorIds);
        if ($recipients === []) {
            throw new InvalidArgumentException($everyone ? 'No one has an email address.' : 'Choose a consignor.');
        }
        $id = $this->newId();
        $this->statements->transaction(function () use ($id, $subject, $message, $recipients, $date): void {
            $this->statements->change(
                'INSERT INTO vendor_letters (id, subject, body) VALUES (?::uuid, ?, ?)',
                [$id, $subject, $message]
            );
            foreach ($recipients as $person) {
                $this->statements->change(
                    'INSERT INTO vendor_letter_copies (letter_id, vendor_id, email) VALUES (?::uuid, ?::uuid, ?)',
                    [$id, $person['id'], $person['email']]
                );
            }
            $this->queue->enqueueLetter($this->pdo, $date, $id);
        });

        return $id;
    }

    /**
     * @return list<array{id: string, name: string, email: string, usable: bool, reason: string}>
     */
    public function people(): array
    {
        $rows = $this->statements->rows(
            'SELECT id::text AS id, name, COALESCE(email, \'\') AS email, email_bounced
             FROM vendors
             WHERE active = true
             ORDER BY name, code'
        );
        $people = [];
        foreach ($rows as $row) {
            $email = RowValue::string($row['email'] ?? null);
            $bounced = $this->on($row['email_bounced'] ?? false);
            $reason = '';
            if ($email === '') {
                $reason = 'No email address';
            } elseif ($bounced) {
                $reason = 'Bounced';
            }
            $people[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'name' => RowValue::string($row['name'] ?? null),
                'email' => $email,
                'usable' => $reason === '',
                'reason' => $reason,
            ];
        }

        return $people;
    }

    /**
     * @return list<array{id: string, subject: string, filed_on: string, copies: int}>
     */
    public function letters(): array
    {
        $rows = $this->statements->rows(
            'SELECT letter.id::text AS id, letter.subject, letter.created_at::text AS filed_on,
                    (SELECT count(*) FROM vendor_letter_copies AS copy WHERE copy.letter_id = letter.id) AS copies
             FROM vendor_letters AS letter
             ORDER BY letter.created_at DESC, letter.id
             LIMIT 40'
        );
        $letters = [];
        foreach ($rows as $row) {
            $letters[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'subject' => RowValue::string($row['subject'] ?? null),
                'filed_on' => RowValue::string($row['filed_on'] ?? null),
                'copies' => RowValue::integer($row['copies'] ?? null),
            ];
        }

        return $letters;
    }

    /**
     * @return array{subject: string, body: string, filed_on: string, copies: list<array{name: string, email: string, status: string}>}|null
     */
    public function openLetter(string $id): ?array
    {
        if (!$this->uuid($id)) {
            return null;
        }
        $rows = $this->statements->bound(
            'SELECT subject, body, created_at::text AS filed_on FROM vendor_letters WHERE id = ?::uuid',
            [$id]
        );
        $letter = $rows[0] ?? null;
        if (!is_array($letter)) {
            return null;
        }
        $copies = $this->statements->bound(
            'SELECT vendor.name, copy.email, copy.sent_at::text AS sent_at, vendor.email_bounced
             FROM vendor_letter_copies AS copy
             JOIN vendors AS vendor ON vendor.id = copy.vendor_id
             WHERE copy.letter_id = ?::uuid
             ORDER BY vendor.name, copy.email',
            [$id]
        );
        $listed = [];
        foreach ($copies as $copy) {
            $sent = $copy['sent_at'] ?? null;
            $bounced = $this->on($copy['email_bounced'] ?? false);
            $status = 'Waiting';
            if (is_string($sent) && $sent !== '') {
                $status = 'Sent';
            } elseif ($bounced) {
                $status = 'Bounced';
            }
            $listed[] = [
                'name' => RowValue::string($copy['name'] ?? null),
                'email' => RowValue::string($copy['email'] ?? null),
                'status' => $status,
            ];
        }

        return [
            'subject' => RowValue::string($letter['subject'] ?? null),
            'body' => RowValue::string($letter['body'] ?? null),
            'filed_on' => RowValue::string($letter['filed_on'] ?? null),
            'copies' => $listed,
        ];
    }

    /**
     * @return list<array{id: string, email: string}>
     */
    private function eligible(): array
    {
        $rows = $this->statements->rows(
            "SELECT id::text AS id, email
             FROM vendors
             WHERE active = true
               AND email IS NOT NULL
               AND email <> ''
               AND NOT email_bounced
             ORDER BY name, code"
        );
        $people = [];
        foreach ($rows as $row) {
            $people[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'email' => RowValue::string($row['email'] ?? null),
            ];
        }

        return $people;
    }

    /**
     * @param list<string> $vendorIds
     * @return list<array{id: string, email: string}>
     */
    private function chosen(array $vendorIds): array
    {
        $ids = [];
        foreach ($vendorIds as $vendorId) {
            if (!$this->uuid($vendorId)) {
                throw new InvalidArgumentException('That consignor is not on the list.');
            }
            $ids[$vendorId] = $vendorId;
        }
        if ($ids === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($ids), '?::uuid'));
        $rows = $this->statements->bound(
            "SELECT id::text AS id, email
             FROM vendors
             WHERE active = true
               AND email IS NOT NULL
               AND email <> ''
               AND NOT email_bounced
               AND id IN ($marks)
             ORDER BY name, code",
            array_values($ids)
        );
        if (count($rows) !== count($ids)) {
            throw new InvalidArgumentException('That consignor is not on the list.');
        }
        $people = [];
        foreach ($rows as $row) {
            $people[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'email' => RowValue::string($row['email'] ?? null),
            ];
        }

        return $people;
    }

    private function newId(): string
    {
        $rows = $this->statements->rows('SELECT uuidv7() AS id');

        return RowValue::string($rows[0]['id'] ?? null);
    }

    private function uuid(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1;
    }

    private function on(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
