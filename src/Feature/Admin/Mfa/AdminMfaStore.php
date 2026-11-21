<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin\Mfa;

use PerfectConsign\Feature\Admin\AdminAudit;
use PDO;

final class AdminMfaStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AdminAudit $audit,
    ) {
    }

    public function hasSecret(string $adminId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT mfa_secret
             FROM platform.admins
             WHERE id = ?::uuid'
        );
        $statement->execute([$adminId]);
        $secret = $statement->fetchColumn();

        return is_string($secret) && $secret !== '';
    }

    public function version(string $adminId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT credentials_version
             FROM platform.admins
             WHERE id = ?::uuid'
        );
        $statement->execute([$adminId]);
        $version = $statement->fetchColumn();
        if (is_int($version)) {
            return (string) $version;
        }
        if (!is_string($version) || ctype_digit($version) !== true) {
            return null;
        }

        return $version;
    }

    /**
     * @return array{secret: string, step: int}|null
     */
    public function secret(string $adminId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT mfa_secret, mfa_last_used_step
             FROM platform.admins
             WHERE id = ?::uuid
               AND mfa_secret IS NOT NULL
               AND mfa_confirmed_at IS NOT NULL'
        );
        $statement->execute([$adminId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $secret = $row['mfa_secret'] ?? null;
        $step = $row['mfa_last_used_step'] ?? null;
        if (is_string($step) && ctype_digit($step)) {
            $step = (int) $step;
        }
        if (!is_string($secret) || $secret === '' || !is_int($step)) {
            return null;
        }

        return ['secret' => $secret, 'step' => $step];
    }

    public function acceptedStep(string $adminId, string $code, int $now): ?int
    {
        $stored = $this->secret($adminId);
        if ($stored === null) {
            return null;
        }
        $raw = AdminMfaSeal::open($stored['secret']);

        return Totp::verify($raw, $code, $now, $stored['step']);
    }

    /**
     * @param list<string> $hashes
     */
    public function enroll(string $adminId, string $email, string $sealed, int $step, array $hashes): bool
    {
        $this->pdo->beginTransaction();
        try {
            $saved = $this->pdo->prepare(
                'UPDATE platform.admins
                 SET mfa_secret = ?,
                     mfa_confirmed_at = clock_timestamp(),
                     mfa_last_used_step = ?
                 WHERE id = ?::uuid
                   AND mfa_secret IS NULL
                   AND mfa_confirmed_at IS NULL
                   AND mfa_last_used_step IS NULL
                 RETURNING id::text'
            );
            $saved->execute([$sealed, $step, $adminId]);
            $id = $saved->fetchColumn();
            if (!is_string($id) || $id === '') {
                $this->pdo->rollBack();

                return false;
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO platform.admin_mfa_recovery_codes (admin_id, code_hash)
                 VALUES (?::uuid, ?)'
            );
            foreach ($hashes as $hash) {
                $insert->execute([$adminId, $hash]);
            }
            $this->audit->record($adminId, $email, 'admin.mfa_enrolled', 'ok');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return true;
    }

    public function advance(string $adminId, int $step): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE platform.admins
             SET mfa_last_used_step = ?
             WHERE id = ?::uuid
               AND mfa_last_used_step IS NOT NULL
               AND mfa_last_used_step < ?
             RETURNING id::text'
        );
        $statement->execute([$step, $adminId, $step]);
        $id = $statement->fetchColumn();

        return is_string($id) && $id !== '';
    }

    public function burn(string $adminId, string $hash): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE platform.admin_mfa_recovery_codes
             SET used_at = clock_timestamp()
             WHERE admin_id = ?::uuid
               AND code_hash = ?
               AND used_at IS NULL
             RETURNING id::text'
        );
        $statement->execute([$adminId, $hash]);
        $id = $statement->fetchColumn();

        return is_string($id) && $id !== '';
    }
}
