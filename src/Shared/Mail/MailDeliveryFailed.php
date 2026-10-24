<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

use RuntimeException;

final class MailDeliveryFailed extends RuntimeException
{
    public function __construct(
        private readonly int $status,
        private readonly string $stage,
    ) {
        parent::__construct('smtp send failed');
    }

    public function status(): int
    {
        return $this->status;
    }

    public function stage(): string
    {
        return $this->stage;
    }

    public function permanent(): bool
    {
        return $this->stage === 'rcpt' && $this->status >= 500 && $this->status <= 599;
    }
}
