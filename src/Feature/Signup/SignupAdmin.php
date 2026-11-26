<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Signup;

use PDO;
use PDOException;
use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Shared\Scalar\RowValue;
use RuntimeException;

/**
 * What an operator does with signups: create a store through the same build
 * queue, send a waiting signup a fresh link, discard one, and read the lists
 * on the Signups page.
 */
final class SignupAdmin
{
    /** Statuses the Signups page lists, newest first. */
    private const LISTED = ['pending', 'verified', 'provisioning', 'failed', 'ready'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly SignupMail $mail,
        private readonly AdminAudit $audit,
    ) {
    }

    /**
     * @param array{id: string, email: string} $admin
     */
    public function create(string $storeName, string $email, string $password, string $confirm, array $admin): string
    {
        [$storeName, $email] = SignupInput::checked($storeName, $email, $password, $confirm);
        $statement = $this->pdo->prepare('SELECT platform.signup_admin_create(?, ?, ?, ?, ?::uuid)');
        try {
            $statement->execute([
                $email,
                $storeName,
                password_hash($password, PASSWORD_DEFAULT),
                SignupToken::issue()['hash'],
                $admin['id'],
            ]);
        } catch (PDOException $error) {
            throw new RuntimeException(self::refusal($error, 'That store could not be queued.'), 0, $error);
        }
        $this->audit->record($admin['id'], $admin['email'], 'store.admin_create', 'ok', null, null, [
            'display_name' => $storeName,
            'owner_email' => $email,
        ]);

        return $email;
    }

    /**
     * @param array{id: string, email: string} $admin
     */
    public function resend(string $signupId, string $origin, array $admin, int $hours): string
    {
        $link = SignupToken::issue();
        $statement = $this->pdo->prepare('SELECT platform.signup_resend(?::uuid, ?)');
        try {
            $statement->execute([$signupId, $link['hash']]);
        } catch (PDOException $error) {
            throw new RuntimeException(self::refusal($error, 'That signup was not found.'), 0, $error);
        }
        $email = $statement->fetchColumn();
        if (!is_string($email)) {
            throw new RuntimeException('That signup was not found.');
        }
        if (!$this->mail->link($email, $origin, $link['token'], $hours)) {
            throw new RuntimeException('The new link could not be emailed. Try again.');
        }
        $this->audit->record($admin['id'], $admin['email'], 'store.signup_resend', 'ok', null, null, [
            'owner_email' => $email,
        ]);

        return $email;
    }

    /**
     * @param array{id: string, email: string} $admin
     */
    public function discard(string $signupId, array $admin): string
    {
        $statement = $this->pdo->prepare('SELECT platform.signup_discard(?::uuid)');
        try {
            $statement->execute([$signupId]);
        } catch (PDOException $error) {
            throw new RuntimeException(self::refusal($error, 'That signup was not found.'), 0, $error);
        }
        $email = $statement->fetchColumn();
        if (!is_string($email)) {
            throw new RuntimeException('That signup was not found.');
        }
        $this->audit->record($admin['id'], $admin['email'], 'store.signup_discard', 'ok', null, null, [
            'owner_email' => $email,
        ]);

        return $email;
    }

    /**
     * @return list<array{id: string, email: string, store_name: string, status: string, created_at: string, expires_at: string, slug: string, last_error: string, by_operator: bool}>
     */
    public function listed(): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id::text AS id, email, store_name, status,
                    to_char(created_at, 'YYYY-MM-DD HH24:MI') AS created_at,
                    to_char(expires_at, 'YYYY-MM-DD HH24:MI') AS expires_at,
                    coalesce(slug, '') AS slug, coalesce(last_error, '') AS last_error,
                    admin_id IS NOT NULL AS by_operator
             FROM platform.store_signups
             WHERE status = ANY (?::text[])
             ORDER BY created_at DESC
             LIMIT 200"
        );
        $statement->execute(['{' . implode(',', self::LISTED) . '}']);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'email' => RowValue::string($row['email'] ?? null),
                'store_name' => RowValue::string($row['store_name'] ?? null),
                'status' => RowValue::string($row['status'] ?? null),
                'created_at' => RowValue::string($row['created_at'] ?? null),
                'expires_at' => RowValue::string($row['expires_at'] ?? null),
                'slug' => RowValue::string($row['slug'] ?? null),
                'last_error' => RowValue::string($row['last_error'] ?? null),
                'by_operator' => ($row['by_operator'] ?? false) === true,
            ];
        }

        return $rows;
    }

    /** Unconfirmed signups the daily clean-up removed in the last 30 days. */
    public function reapedLately(): int
    {
        $statement = $this->pdo->query(
            "SELECT coalesce(sum(expired), 0)::int FROM platform.signup_reaped
             WHERE day > (clock_timestamp() AT TIME ZONE 'UTC')::date - 30"
        );
        if ($statement === false) {
            throw new RuntimeException('signup counts could not be read');
        }

        return RowValue::integer($statement->fetchColumn());
    }

    /**
     * Stores built before signup waited for the link, whose owner never confirmed
     * the email. Nothing deletes them on its own.
     *
     * @return list<array{slug: string, display_name: string, email: string, created_at: string}>
     */
    public function unconfirmedStores(): array
    {
        $statement = $this->pdo->query(
            "SELECT tenant.slug, tenant.display_name, coalesce(owner.email, '') AS email,
                    to_char(tenant.created_at, 'YYYY-MM-DD') AS created_at
             FROM platform.tenants AS tenant
             JOIN platform.owners AS owner ON owner.tenant_id = tenant.id
             WHERE owner.email_verified_at IS NULL
             ORDER BY tenant.created_at, tenant.slug"
        );
        if ($statement === false) {
            throw new RuntimeException('unconfirmed stores could not be read');
        }
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'slug' => RowValue::string($row['slug'] ?? null),
                'display_name' => RowValue::string($row['display_name'] ?? null),
                'email' => RowValue::string($row['email'] ?? null),
                'created_at' => RowValue::string($row['created_at'] ?? null),
            ];
        }

        return $rows;
    }

    /** The plain sentence a signup function raised, or the fallback. */
    private static function refusal(PDOException $error, string $fallback): string
    {
        $state = (string) $error->getCode();
        if ($state !== '23505' && $state !== '23514') {
            return $fallback;
        }
        $message = $error->errorInfo[2] ?? '';
        if (!is_string($message) || preg_match('/ERROR:\s+([A-Z][^\n]*\.)/', $message, $match) !== 1) {
            return $fallback;
        }

        return $match[1];
    }
}
