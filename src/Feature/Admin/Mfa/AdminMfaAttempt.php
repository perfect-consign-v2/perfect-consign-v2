<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin\Mfa;

final class AdminMfaAttempt
{
    private function __construct(
        private readonly string $kind,
        private readonly string $message,
    ) {
    }

    public static function success(): self
    {
        return new self('opened', '');
    }

    public static function invalid(string $message): self
    {
        return new self('invalid', $message);
    }

    public function opened(): bool
    {
        return $this->kind === 'opened';
    }

    public function message(): string
    {
        return $this->message;
    }
}
