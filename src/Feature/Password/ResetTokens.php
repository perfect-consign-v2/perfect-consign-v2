<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;

final class ResetTokens
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function issue(TenantRecord $tenant, LoginAccount $account): string
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->pdo->beginTransaction();
        try {
            $this->expire($tenant, $account);
            $this->insert($tenant, $account, hash('sha256', $raw));
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }

        return $raw;
    }

    public function available(TenantRecord $tenant, string $token): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return false;
        }
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM platform.password_resets
             WHERE tenant_id = ?::uuid
               AND token_hash = ?
               AND used_at IS NULL
               AND expires_at > clock_timestamp()'
        );
        $statement->execute([$tenant->id, hash('sha256', $token)]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array{id: string, kind: string, account: string}|null
     */
    public function lock(TenantRecord $tenant, string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, account_kind, account_id::text AS account_id
             FROM platform.password_resets
             WHERE tenant_id = ?::uuid
               AND token_hash = ?
               AND used_at IS NULL
               AND expires_at > clock_timestamp()
             FOR UPDATE'
        );
        $statement->execute([$tenant->id, hash('sha256', $token)]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $id = $row['id'] ?? null;
        $accountId = $row['account_id'] ?? null;
        $kind = $row['account_kind'] ?? null;
        if (!is_string($id) || !is_string($accountId)) {
            return null;
        }
        if ($kind !== 'owner' && $kind !== 'staff') {
            return null;
        }

        return ['id' => $id, 'kind' => $kind, 'account' => $accountId];
    }

    public function consume(string $id): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE platform.password_resets
             SET used_at = clock_timestamp()
             WHERE id = ?::uuid AND used_at IS NULL'
        );
        $statement->execute([$id]);
        if ($statement->rowCount() !== 1) {
            throw new \InvalidArgumentException(ResetNotice::EXPIRED);
        }
    }

    private function expire(TenantRecord $tenant, LoginAccount $account): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE platform.password_resets
             SET used_at = clock_timestamp()
             WHERE tenant_id = ?::uuid
               AND account_kind = ?
               AND account_id = ?::uuid
               AND used_at IS NULL'
        );
        $statement->execute([$tenant->id, $account->kind, $account->id]);
    }

    private function insert(TenantRecord $tenant, LoginAccount $account, string $hash): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO platform.password_resets (tenant_id, account_kind, account_id, token_hash, expires_at)
             VALUES (?::uuid, ?, ?::uuid, ?, clock_timestamp() + interval \'30 minutes\')'
        );
        $statement->execute([$tenant->id, $account->kind, $account->id, $hash]);
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
