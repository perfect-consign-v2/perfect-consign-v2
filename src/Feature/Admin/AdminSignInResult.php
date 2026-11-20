<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

final class AdminSignInResult
{
    private function __construct(private readonly string $kind)
    {
    }

    public static function refused(): self
    {
        return new self('refused');
    }

    public static function enroll(): self
    {
        return new self('enroll');
    }

    public static function challenge(): self
    {
        return new self('challenge');
    }

    public function accepted(): bool
    {
        return $this->kind !== 'refused';
    }

    public function needsEnrollment(): bool
    {
        return $this->kind === 'enroll';
    }
}
