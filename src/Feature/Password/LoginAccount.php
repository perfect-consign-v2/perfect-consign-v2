<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

final class LoginAccount
{
    public function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly string $username,
        public readonly string $email,
    ) {
    }
}
