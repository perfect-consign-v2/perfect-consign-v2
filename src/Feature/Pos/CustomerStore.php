<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class CustomerStore
{
    public function __construct(private readonly Statements $statements)
    {
    }

    public function findOrCreate(string $name, string $phone, string $email): string
    {
        $name = trim($name);
        $phone = trim($phone);
        $email = trim($email);
        $this->accept($name, $phone, $email);
        $existing = $this->statements->bound(
            'SELECT id FROM customers
             WHERE lower(name) = lower(?)
               AND COALESCE(phone, \'\') = ?
               AND COALESCE(email, \'\') = ?
             ORDER BY created_at
             LIMIT 1',
            [$name, $phone, $email]
        );
        $found = $existing[0]['id'] ?? null;
        if (is_string($found) && $found !== '') {
            return $found;
        }
        $created = $this->statements->bound(
            'INSERT INTO customers (name, phone, email) VALUES (?, NULLIF(?, \'\'), NULLIF(?, \'\')) RETURNING id',
            [$name, $phone, $email]
        );

        return RowValue::string($created[0]['id'] ?? null);
    }

    private function accept(string $name, string $phone, string $email): void
    {
        if ($name === '' || strlen($name) > 120) {
            throw new DatabaseRefusal('Enter the customer name.');
        }
        if ($phone !== '' && strlen($phone) > 40) {
            throw new DatabaseRefusal('That phone number is too long.');
        }
        if ($email !== '' && strlen($email) > 200) {
            throw new DatabaseRefusal('Enter a real email or leave it blank.');
        }
        if ($email !== '' && preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1) {
            throw new DatabaseRefusal('Enter a real email or leave it blank.');
        }
    }
}
