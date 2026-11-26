<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;

/**
 * The platform's store and sign-in jobs: build the store for a confirmed
 * signup, and the two daily clean-ups. platform.signup_reap deletes
 * unconfirmed signups past their deadline and finished ones after the kept
 * days; platform.sign_in_reap deletes sign-in attempts after
 * attempts_kept_days and devices not seen for device_days.
 */
final class PlatformJobs
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

    public function reapSignups(): void
    {
        $this->pdo->query('SELECT platform.signup_reap()');
    }

    public function reapSignIns(): void
    {
        $this->pdo->query('SELECT platform.sign_in_reap()');
    }
}
