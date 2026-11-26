<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PDO;

/**
 * Deletes authenticator-code failure rows older than the fifteen-minute lock
 * window. Called before each new failure insert so rotated identities cannot
 * grow the table without bound. Password attempts are platform.sign_in_attempts.
 */
final class SignInFailureRetention
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function pruneAdminMfa(string $email): void
    {
        $forEmail = $this->pdo->prepare(
            "DELETE FROM platform.admin_mfa_failures
             WHERE email = ?
               AND failed_at <= clock_timestamp() - interval '15 minutes'"
        );
        $forEmail->execute([$email]);
        $stale = $this->pdo->prepare(
            "DELETE FROM platform.admin_mfa_failures
             WHERE failed_at <= clock_timestamp() - interval '15 minutes'"
        );
        $stale->execute();
    }
}
