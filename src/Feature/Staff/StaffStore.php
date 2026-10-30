<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Staff;

use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\RecoveryAddress;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class StaffStore
{
    public function __construct(
        private readonly Statements $statements,
        private readonly RecoveryAddress $addresses,
        private readonly PasswordChoice $passwords,
    ) {
    }

    /**
     * @return list<array{id: string, username: string, display_name: string, role: string, email: string}>
     */
    public function cashiers(): array
    {
        return $this->people('cashier');
    }

    /**
     * @return list<array{id: string, username: string, display_name: string, role: string, email: string}>
     */
    public function managers(): array
    {
        return $this->people('manager');
    }

    /**
     * @return list<array{id: string, username: string, display_name: string, role: string, email: string}>
     */
    public function books(): array
    {
        return $this->people('books');
    }

    /**
     * @return list<array{id: string, username: string, display_name: string, role: string, email: string}>
     */
    private function people(string $role): array
    {
        $rows = $this->statements->bound(
            "SELECT id, username, display_name, role, email
             FROM staff
             WHERE role = ?
             ORDER BY lower(display_name), username",
            [$role]
        );
        $people = [];
        foreach ($rows as $row) {
            $people[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'username' => RowValue::string($row['username'] ?? null),
                'display_name' => RowValue::string($row['display_name'] ?? null),
                'role' => RowValue::string($row['role'] ?? null),
                'email' => RowValue::optionalString($row['email'] ?? null) ?? '',
            ];
        }

        return $people;
    }

    public function addCashier(string $username, string $displayName, string $password, string $email): void
    {
        $this->add($username, $displayName, $password, $this->addresses->parse($email), 'cashier', null);
    }

    public function addManager(string $username, string $displayName, string $password, string $email): void
    {
        $this->add($username, $displayName, $password, $this->addresses->parse($email), 'manager', null);
    }

    public function addBooks(string $username, string $displayName, string $password, string $email): void
    {
        $this->add($username, $displayName, $password, $this->addresses->parse($email), 'books', null);
    }

    public function replaceCashier(string $id, string $password, string $email): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Choose a cashier.');
        }
        $saved = $this->statements->affected(
            "UPDATE staff
             SET password_hash = ?, email = ?, credentials_version = credentials_version + 1
             WHERE id = ?::uuid AND role = 'cashier'",
            [$this->passwords->hash($password), $this->addresses->parse($email), $id]
        );
        if ($saved < 1) {
            throw new \InvalidArgumentException('Choose a cashier.');
        }
    }

    public function replaceManager(string $id, string $password, string $email): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Choose a manager.');
        }
        $saved = $this->statements->affected(
            "UPDATE staff
             SET password_hash = ?, email = ?, credentials_version = credentials_version + 1
             WHERE id = ?::uuid AND role = 'manager'",
            [$this->passwords->hash($password), $this->addresses->parse($email), $id]
        );
        if ($saved < 1) {
            throw new \InvalidArgumentException('Choose a manager.');
        }
    }

    public function replaceBooks(string $id, string $password, string $email): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Choose a books login.');
        }
        $saved = $this->statements->affected(
            "UPDATE staff
             SET password_hash = ?, email = ?, credentials_version = credentials_version + 1
             WHERE id = ?::uuid AND role = 'books'",
            [$this->passwords->hash($password), $this->addresses->parse($email), $id]
        );
        if ($saved < 1) {
            throw new \InvalidArgumentException('Choose a books login.');
        }
    }

    public function addVendor(string $username, string $displayName, string $password, string $email, string $vendorId): void
    {
        $this->saveVendor($username, $displayName, $password, $email, $vendorId);
    }

    public function saveVendor(string $username, string $displayName, string $password, string $email, string $vendorId): bool
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1) {
            throw new \InvalidArgumentException('Choose a vendor.');
        }
        $prepared = $this->prepared($username, $displayName, $password);
        $address = $this->addresses->parse($email);
        $existing = $this->statements->bound(
            "SELECT id::text AS id FROM staff WHERE role = 'vendor' AND vendor_id = ?::uuid",
            [$vendorId]
        );
        if ($existing !== []) {
            $this->statements->change(
                "UPDATE staff
                 SET username = ?, display_name = ?, password_hash = ?, email = ?,
                     credentials_version = credentials_version + 1
                 WHERE role = 'vendor' AND vendor_id = ?::uuid",
                [$prepared['username'], $prepared['displayName'], $prepared['hash'], $address, $vendorId]
            );

            return true;
        }
        $this->statements->change(
            'INSERT INTO staff (username, display_name, password_hash, role, vendor_id, email)
             VALUES (?, ?, ?, ?, ?::uuid, ?)',
            [$prepared['username'], $prepared['displayName'], $prepared['hash'], 'vendor', $vendorId, $address]
        );

        return false;
    }

    /**
     * @return array{username: string, display_name: string, email: string}|null
     */
    public function vendorSignIn(string $vendorId): ?array
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1) {
            return null;
        }
        $rows = $this->statements->bound(
            "SELECT username, display_name, email
             FROM staff
             WHERE role = 'vendor' AND vendor_id = ?::uuid",
            [$vendorId]
        );
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }
        $username = $row['username'] ?? null;
        $displayName = $row['display_name'] ?? null;
        if (!is_string($username) || !is_string($displayName)) {
            return null;
        }

        return [
            'username' => $username,
            'display_name' => $displayName,
            'email' => RowValue::optionalString($row['email'] ?? null) ?? '',
        ];
    }

    private function add(string $username, string $displayName, string $password, string $email, string $role, ?string $vendorId): void
    {
        $prepared = $this->prepared($username, $displayName, $password);
        $this->statements->change(
            'INSERT INTO staff (username, display_name, password_hash, role, vendor_id, email)
             VALUES (?, ?, ?, ?, ?::uuid, ?)',
            [$prepared['username'], $prepared['displayName'], $prepared['hash'], $role, $vendorId, $email]
        );
    }

    /**
     * @return array{username: string, displayName: string, hash: string}
     */
    private function prepared(string $username, string $displayName, string $password): array
    {
        $username = strtolower(trim($username));
        $displayName = trim($displayName);
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $username) !== 1) {
            throw new \InvalidArgumentException('Use a sign-in name of letters, numbers, and hyphens.');
        }
        if ($displayName === '' || strlen($displayName) > 80) {
            throw new \InvalidArgumentException('Enter the name people will see.');
        }

        return [
            'username' => $username,
            'displayName' => $displayName,
            'hash' => $this->passwords->hash($password),
        ];
    }
}
