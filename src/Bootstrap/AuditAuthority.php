<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use InvalidArgumentException;

final class AuditAuthority
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $username,
    ) {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $this->accountId) !== 1) {
            throw new InvalidArgumentException('a second signer is a different person');
        }
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $this->username) !== 1) {
            throw new InvalidArgumentException('a second signer is a different person');
        }
    }
}
