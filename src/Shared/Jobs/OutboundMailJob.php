<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Shared\Mail\MailOutbox;
use PerfectConsign\Shared\Mail\PlatformMailbox;
use RuntimeException;

final class OutboundMailJob
{
    public function __construct(
        private readonly MailOutbox $outbox,
        private readonly LetterMailbox $mailbox,
        private readonly PlatformMailbox $platform,
    ) {
    }

    public function send(string $mailId): void
    {
        $row = $this->outbox->open($mailId);
        if ($row === null) {
            throw new RuntimeException('a mail is missing');
        }
        if ($row['sent_at'] !== '') {
            return;
        }
        if ($row['mailbox'] === MailOutbox::MAILBOX_MALL) {
            $this->mailbox->send($row['address'], $row['subject'], $row['body'], $row['purpose']);
            $this->outbox->markSent($row['id']);

            return;
        }
        if ($row['mailbox'] !== MailOutbox::MAILBOX_PLATFORM) {
            throw new RuntimeException('the mailbox is not a mailbox');
        }
        $this->platform->send($row['address'], $row['subject'], $row['body'], $row['purpose']);
        $this->outbox->markSent($row['id']);
    }
}
