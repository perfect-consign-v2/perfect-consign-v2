<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;

/**
 * The platform's two signup jobs: build the store for a confirmed signup, and
 * the daily clean-up. platform.signup_reap holds the clean-up rules:
 * unconfirmed signups past their deadline are deleted and counted, finished
 * ones go after the kept days, and failed ones wait for an operator.
 */
final class SignupJobs
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly StoreProvisionJob $builder,
    ) {
    }

    public function build(string $signupId): void
    {
        $this->builder->build($signupId);
    }

    public function reap(): void
    {
        $this->pdo->query('SELECT platform.signup_reap()');
    }
}
