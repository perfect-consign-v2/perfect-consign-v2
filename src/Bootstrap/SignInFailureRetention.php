<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PDO;

/**
 * Deletes sign-in failure rows older than the fifteen-minute lock window.
 * Called before each new failure insert so rotated identities cannot grow
 * the throttle tables without bound.
 */
final class SignInFailureRetention
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function pruneEmail(string $email): void
    {
        $forEmail = $this->pdo->prepare(
            "DELETE FROM platform.email_sign_in_failures
             WHERE email = ?
               AND failed_at <= clock_timestamp() - interval '15 minutes'"
        );
        $forEmail->execute([$email]);
        $stale = $this->pdo->prepare(
            "DELETE FROM platform.email_sign_in_failures
             WHERE failed_at <= clock_timestamp() - interval '15 minutes'"
        );
        $stale->execute();
    }

    public function pruneOwner(string $tenantId, string $username): void
    {
        $forIdentity = $this->pdo->prepare(
            "DELETE FROM platform.owner_sign_in_failures
             WHERE tenant_id = ?::uuid
               AND username = ?
               AND failed_at <= clock_timestamp() - interval '15 minutes'"
        );
        $forIdentity->execute([$tenantId, $username]);
        $stale = $this->pdo->prepare(
            "DELETE FROM platform.owner_sign_in_failures
             WHERE failed_at <= clock_timestamp() - interval '15 minutes'"
        );
        $stale->execute();
    }

    public function pruneAdmin(string $email): void
    {
        $forEmail = $this->pdo->prepare(
            "DELETE FROM platform.admin_sign_in_failures
             WHERE email = ?
               AND failed_at <= clock_timestamp() - interval '15 minutes'"
        );
        $forEmail->execute([$email]);
        $stale = $this->pdo->prepare(
            "DELETE FROM platform.admin_sign_in_failures
             WHERE failed_at <= clock_timestamp() - interval '15 minutes'"
        );
        $stale->execute();
    }
}
