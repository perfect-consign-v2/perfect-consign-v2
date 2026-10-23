<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

use RuntimeException;

final class MailDeliveryFailed extends RuntimeException
{
    public function __construct(private readonly int $status)
    {
        parent::__construct('smtp send failed');
    }

    public function status(): int
    {
        return $this->status;
    }

    public function permanent(): bool
    {
        return $this->status >= 500 && $this->status <= 599;
    }
}
