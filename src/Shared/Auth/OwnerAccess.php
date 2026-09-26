<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Auth;

final class OwnerAccess
{
    public function isForeign(?string $sessionSlug, string $urlSlug): bool
    {
        return $sessionSlug !== null && $sessionSlug !== $urlSlug;
    }
}
