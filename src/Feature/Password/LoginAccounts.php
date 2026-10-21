<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;

final class LoginAccounts
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabaseRole $roles,
    ) {
    }

    public function find(TenantRecord $tenant, string $username): ?LoginAccount
    {
        $owner = $this->owner($tenant->id, $username);
        if ($owner !== null) {
            return $owner;
        }

        return $this->staff($tenant, $username);
    }

    public function shown(TenantRecord $tenant, string $role, string $accountId): string
    {
        if ($role === 'owner') {
            $this->roles->reset();
            try {
                return $this->ownerEmail($tenant->id, $accountId);
            } finally {
                $this->roles->assume($tenant->role);
            }
        }

        return $this->staffEmail($tenant->schema, $accountId);
    }

    private function owner(string $tenantId, string $username): ?LoginAccount
    {
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, email
             FROM platform.owners
             WHERE tenant_id = ?::uuid AND username = ?'
        );
        $statement->execute([$tenantId, $username]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $id = $row['id'] ?? null;
        if (!is_string($id)) {
            return null;
        }
        $email = $row['email'] ?? null;

        return new LoginAccount('owner', $id, $username, is_string($email) ? $email : '');
    }

    private function staff(TenantRecord $tenant, string $username): ?LoginAccount
    {
        $this->roles->assume($tenant->role);
        try {
            $statement = $this->pdo->prepare(
                'SELECT id::text AS id, email, role
                 FROM ' . $this->table($tenant->schema) . '
                 WHERE lower(username) = lower(?)'
            );
            $statement->execute([$username]);
            $row = $statement->fetch();
        } finally {
            $this->roles->reset();
        }
        if (!is_array($row)) {
            return null;
        }
        $id = $row['id'] ?? null;
        $role = $row['role'] ?? null;
        if (!is_string($id) || ($role !== 'cashier' && $role !== 'vendor')) {
            return null;
        }
        $email = $row['email'] ?? null;

        return new LoginAccount('staff', $id, $username, is_string($email) ? $email : '');
    }

    private function ownerEmail(string $tenantId, string $accountId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT email FROM platform.owners WHERE id = ?::uuid AND tenant_id = ?::uuid'
        );
        $statement->execute([$accountId, $tenantId]);

        return $this->email($statement->fetchColumn());
    }

    private function staffEmail(string $schema, string $accountId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT email FROM ' . $this->table($schema) . ' WHERE id = ?::uuid'
        );
        $statement->execute([$accountId]);

        return $this->email($statement->fetchColumn());
    }

    private function email(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    public function forReset(?LoginAccount $account, string $company): ?LoginAccount
    {
        if ($account === null) {
            return null;
        }
        $email = $account->email;
        if ($email === '' && $account->kind === 'owner') {
            $email = $company;
        }
        if ($email === '') {
            return null;
        }
        if ($email === $account->email) {
            return $account;
        }

        return new LoginAccount($account->kind, $account->id, $account->username, $email);
    }

    /**
     * @return array{email: string, gmail: array{host: string, port: int, username: string, password: string, from: string}|null}
     */
    public function companyMail(TenantRecord $tenant): array
    {
        $schema = $this->schema($tenant->schema);
        $this->roles->assume($tenant->role);
        try {
            return $this->readMailbox($schema);
        } finally {
            $this->roles->reset();
        }
    }

    public function table(string $schema): string
    {
        return $this->schema($schema) . '.staff';
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
            'email' => $this->mailAddress($row['email'] ?? null),
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
        $address = $this->mailAddress($row['gmail_address'] ?? null);
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

    private function mailAddress(mixed $value): string
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

    private function schema(string $schema): string
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new \RuntimeException('the mall schema is not usable');
        }

        return $schema;
    }
}
