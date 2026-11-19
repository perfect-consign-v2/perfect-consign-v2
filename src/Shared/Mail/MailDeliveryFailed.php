<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Mail;

use RuntimeException;

final class MailDeliveryFailed extends RuntimeException
{
    public function __construct(
        private readonly int $status,
        private readonly string $stage,
        private readonly string $reply = '',
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

    public function reply(): string
    {
        return $this->reply;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function context(\Throwable $error, array $context = []): array
    {
        if (!$error instanceof self) {
            return $context;
        }
        $context['status'] = $error->status;
        $context['stage'] = $error->stage;
        if ($error->reply !== '') {
            $context['reply'] = $error->reply;
        }

        return $context;
    }

    public function permanent(): bool
    {
        return $this->stage === 'rcpt' && $this->status >= 500 && $this->status <= 599;
    }
}
