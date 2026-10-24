<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;

final class PasswordRecovery
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabaseRole $roles,
        private readonly LoginAccounts $accounts,
        private readonly ResetTokens $tokens,
        private readonly ResetRequests $requests,
        private readonly PasswordChoice $passwords,
        private readonly ResetLetter $letters,
    ) {
    }

    public function request(TenantRecord $tenant, string $username, string $origin): bool
    {
        $username = $this->username($username);
        if ($username === '' || !$this->requests->allow($tenant, $username)) {
            return true;
        }
        $account = $this->accounts->find($tenant, $username);
        if ($account === null) {
            return true;
        }

        return $this->letters->sendReset($tenant, $account, $origin, $this->accounts);
    }

    public function available(TenantRecord $tenant, string $token): bool
    {
        return $this->tokens->available($tenant, $token);
    }

    public function redeem(TenantRecord $tenant, string $token, string $password, string $confirm): void
    {
        $this->pdo->beginTransaction();
        try {
            $locked = $this->tokens->lock($tenant, $token);
            if ($locked === null) {
                throw new \InvalidArgumentException(ResetNotice::EXPIRED);
            }
            $identity = $this->identity($tenant, $locked);
            $this->replace($tenant, $locked, $identity['hash'], $password, $confirm);
            $this->tokens->consume($locked['id']);
            $this->clear($tenant->id, $identity['username']);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    /**
     * @param array{id: string, kind: string, account: string} $locked
     */
    private function replace(TenantRecord $tenant, array $locked, string $currentHash, string $password, string $confirm): void
    {
        $hash = $this->passwords->replacement($currentHash, $password, $confirm);
        if ($locked['kind'] === 'owner') {
            $this->writeOwner($tenant->id, $locked['account'], $hash);

            return;
        }
        $this->roles->assume($tenant->role);
        try {
            $this->writeStaff($this->accounts->table($tenant->schema), $locked['account'], $hash);
        } finally {
            $this->roles->reset();
        }
    }

    /**
     * @param array{id: string, kind: string, account: string} $locked
     * @return array{hash: string, username: string}
     */
    private function identity(TenantRecord $tenant, array $locked): array
    {
        if ($locked['kind'] === 'owner') {
            $statement = $this->pdo->prepare(
                'SELECT username, password_hash FROM platform.owners WHERE id = ?::uuid AND tenant_id = ?::uuid'
            );
            $statement->execute([$locked['account'], $tenant->id]);
            $row = $statement->fetch();
        } else {
            $this->roles->assume($tenant->role);
            try {
                $statement = $this->pdo->prepare(
                    'SELECT username, password_hash FROM ' . $this->accounts->table($tenant->schema) . ' WHERE id = ?::uuid'
                );
                $statement->execute([$locked['account']]);
                $row = $statement->fetch();
            } finally {
                $this->roles->reset();
            }
        }
        $username = is_array($row) ? ($row['username'] ?? null) : null;
        $hash = is_array($row) ? ($row['password_hash'] ?? null) : null;
        if (!is_string($username) || !is_string($hash) || $hash === '') {
            throw new \InvalidArgumentException(ResetNotice::EXPIRED);
        }

        return ['hash' => $hash, 'username' => $username];
    }

    private function writeOwner(string $tenantId, string $accountId, string $hash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE platform.owners
             SET password_hash = ?, credentials_version = credentials_version + 1
             WHERE id = ?::uuid AND tenant_id = ?::uuid'
        );
        $statement->execute([$hash, $accountId, $tenantId]);
        if ($statement->rowCount() !== 1) {
            throw new \InvalidArgumentException(ResetNotice::EXPIRED);
        }
    }

    private function writeStaff(string $table, string $accountId, string $hash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE ' . $table . '
             SET password_hash = ?, credentials_version = credentials_version + 1
             WHERE id = ?::uuid'
        );
        $statement->execute([$hash, $accountId]);
        if ($statement->rowCount() !== 1) {
            throw new \InvalidArgumentException(ResetNotice::EXPIRED);
        }
    }

    private function clear(string $tenantId, string $username): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM platform.owner_sign_in_failures WHERE tenant_id = ?::uuid AND username = ?'
        );
        $statement->execute([$tenantId, $username]);
    }

    private function username(string $username): string
    {
        $username = strtolower(trim($username));
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $username) !== 1) {
            return '';
        }

        return $username;
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
