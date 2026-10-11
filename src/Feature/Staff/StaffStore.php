<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Staff;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class StaffStore
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array{id: string, username: string, display_name: string, role: string}>
     */
    public function cashiers(): array
    {
        $rows = $this->statements->rows(
            "SELECT id, username, display_name, role
             FROM staff
             WHERE role = 'cashier'
             ORDER BY lower(display_name), username"
        );
        $people = [];
        foreach ($rows as $row) {
            $people[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'username' => RowValue::string($row['username'] ?? null),
                'display_name' => RowValue::string($row['display_name'] ?? null),
                'role' => RowValue::string($row['role'] ?? null),
            ];
        }

        return $people;
    }

    public function addCashier(string $username, string $displayName, string $password): void
    {
        $this->add($username, $displayName, $password, 'cashier', null);
    }

    public function addVendor(string $username, string $displayName, string $password, string $vendorId): void
    {
        $this->saveVendor($username, $displayName, $password, $vendorId);
    }

    public function saveVendor(string $username, string $displayName, string $password, string $vendorId): bool
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1) {
            throw new \InvalidArgumentException('Choose a vendor.');
        }
        $prepared = $this->prepared($username, $displayName, $password);
        $existing = $this->statements->bound(
            "SELECT id::text AS id FROM staff WHERE role = 'vendor' AND vendor_id = ?::uuid",
            [$vendorId]
        );
        if ($existing !== []) {
            $this->statements->change(
                "UPDATE staff
                 SET username = ?, display_name = ?, password_hash = ?
                 WHERE role = 'vendor' AND vendor_id = ?::uuid",
                [$prepared['username'], $prepared['displayName'], $prepared['hash'], $vendorId]
            );

            return true;
        }
        $this->statements->change(
            'INSERT INTO staff (username, display_name, password_hash, role, vendor_id)
             VALUES (?, ?, ?, ?, ?::uuid)',
            [$prepared['username'], $prepared['displayName'], $prepared['hash'], 'vendor', $vendorId]
        );

        return false;
    }

    /**
     * @return array{username: string, display_name: string}|null
     */
    public function vendorSignIn(string $vendorId): ?array
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1) {
            return null;
        }
        $rows = $this->statements->bound(
            "SELECT username, display_name
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

        return ['username' => $username, 'display_name' => $displayName];
    }

    private function add(string $username, string $displayName, string $password, string $role, ?string $vendorId): void
    {
        $prepared = $this->prepared($username, $displayName, $password);
        $this->statements->change(
            'INSERT INTO staff (username, display_name, password_hash, role, vendor_id)
             VALUES (?, ?, ?, ?, ?::uuid)',
            [$prepared['username'], $prepared['displayName'], $prepared['hash'], $role, $vendorId]
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
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Use a password of at least 8 characters.');
        }

        return [
            'username' => $username,
            'displayName' => $displayName,
            'hash' => password_hash($password, PASSWORD_DEFAULT),
        ];
    }
}
