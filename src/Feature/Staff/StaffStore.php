<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Staff;

use PerfectConsign\Ledger\DatabaseRefusal;
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
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1) {
            throw new \InvalidArgumentException('Choose a vendor.');
        }
        $this->add($username, $displayName, $password, 'vendor', $vendorId);
    }

    private function add(string $username, string $displayName, string $password, string $role, ?string $vendorId): void
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
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->statements->change(
            'INSERT INTO staff (username, display_name, password_hash, role, vendor_id)
             VALUES (?, ?, ?, ?, ?::uuid)',
            [$username, $displayName, $hash, $role, $vendorId]
        );
    }
}
