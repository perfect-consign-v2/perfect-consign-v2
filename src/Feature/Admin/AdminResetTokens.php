<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PDO;

final class AdminResetTokens
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function issue(string $accountId): string
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->pdo->beginTransaction();
        try {
            $expire = $this->pdo->prepare(
                'UPDATE platform.admin_password_resets
                 SET used_at = clock_timestamp()
                 WHERE account_id = ?::uuid AND used_at IS NULL'
            );
            $expire->execute([$accountId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO platform.admin_password_resets (account_id, token_hash, expires_at)
                 VALUES (?::uuid, ?, clock_timestamp() + interval \'30 minutes\')'
            );
            $insert->execute([$accountId, hash('sha256', $raw)]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return $raw;
    }

    public function available(string $token): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return false;
        }
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM platform.admin_password_resets
             WHERE token_hash = ?
               AND used_at IS NULL
               AND expires_at > clock_timestamp()'
        );
        $statement->execute([hash('sha256', $token)]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array{id: string, account: string}|null
     */
    public function lock(string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, account_id::text AS account_id
             FROM platform.admin_password_resets
             WHERE token_hash = ?
               AND used_at IS NULL
               AND expires_at > clock_timestamp()
             FOR UPDATE'
        );
        $statement->execute([hash('sha256', $token)]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $id = $row['id'] ?? null;
        $account = $row['account_id'] ?? null;
        if (!is_string($id) || !is_string($account)) {
            return null;
        }

        return ['id' => $id, 'account' => $account];
    }

    public function consume(string $id): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE platform.admin_password_resets
             SET used_at = clock_timestamp()
             WHERE id = ?::uuid AND used_at IS NULL'
        );
        $statement->execute([$id]);
        if ($statement->rowCount() !== 1) {
            throw new \InvalidArgumentException('That reset link expired. Request a new one.');
        }
    }
}
