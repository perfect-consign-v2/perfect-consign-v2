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

    public function request(TenantRecord $tenant, string $username, string $origin): void
    {
        $username = $this->username($username);
        if ($username === '' || !$this->requests->allow($tenant, $username)) {
            return;
        }
        $account = $this->accounts->find($tenant, $username);
        if ($account === null) {
            return;
        }
        $mailbox = $this->mailbox($tenant);
        $email = $this->destination($account, $mailbox['email']);
        if ($email === '') {
            return;
        }
        if ($email !== $account->email) {
            $account = new LoginAccount($account->kind, $account->id, $account->username, $email);
        }
        $this->letters->deliver($tenant, $account, $origin, $mailbox['gmail']);
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
            $this->writeStaff($tenant->schema, $locked['account'], $hash);
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
                    'SELECT username, password_hash FROM ' . $this->table($tenant->schema) . ' WHERE id = ?::uuid'
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

    private function writeStaff(string $schema, string $accountId, string $hash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->table($schema) . '
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

    private function destination(LoginAccount $account, string $company): string
    {
        if ($account->email !== '') {
            return $account->email;
        }
        if ($account->kind !== 'owner') {
            return '';
        }

        return $company;
    }

    /**
     * @return array{email: string, gmail: array{host: string, port: int, username: string, password: string, from: string}|null}
     */
    private function mailbox(TenantRecord $tenant): array
    {
        $schema = $this->schemaName($tenant->schema);
        $this->roles->assume($tenant->role);
        try {
            return $this->readMailbox($schema);
        } finally {
            $this->roles->reset();
        }
    }

    /**
     * @return array{email: string, gmail: array{host: string, port: int, username: string, password: string, from: string}|null}
     */
    private function readMailbox(string $schema): array
    {
        $query = $this->pdo->query(
            'SELECT email, receipt_mail_via, gmail_address, gmail_app_password FROM ' . $schema . '.mall'
        );
        if ($query === false) {
            throw new \RuntimeException('the company email could not be read');
        }
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['email' => '', 'gmail' => null];
        }
        /** @var array<string, mixed> $row */

        return [
            'email' => $this->address($row['email'] ?? null),
            'gmail' => $this->gmail($row),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{host: string, port: int, username: string, password: string, from: string}|null
     */
    private function gmail(array $row): ?array
    {
        if (($row['receipt_mail_via'] ?? null) !== 'gmail') {
            return null;
        }
        $address = $this->address($row['gmail_address'] ?? null);
        $password = $row['gmail_app_password'] ?? null;
        if ($address === '' || !is_string($password) || $password === '') {
            return null;
        }

        return [
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => $address,
            'password' => $password,
            'from' => $address,
        ];
    }

    private function address(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $email = trim($value);
        if ($email === '' || strlen($email) > 200 || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1) {
            return '';
        }

        return $email;
    }

    private function username(string $username): string
    {
        $username = strtolower(trim($username));
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $username) !== 1) {
            return '';
        }

        return $username;
    }

    private function table(string $schema): string
    {
        return $this->schemaName($schema) . '.staff';
    }

    private function schemaName(string $schema): string
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new \RuntimeException('the mall schema is not usable');
        }

        return $schema;
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
