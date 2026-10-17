<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class CustomerDirectory
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array{id: string, name: string, phone: string, email: string}>
     */
    public function listed(string $phone): array
    {
        $phone = trim($phone);
        if ($phone === '') {
            $rows = $this->statements->rows(
                "SELECT id::text AS id, name, COALESCE(phone, '') AS phone, COALESCE(email, '') AS email
                 FROM customers
                 ORDER BY lower(name), name
                 LIMIT 200"
            );
        } else {
            $rows = $this->statements->bound(
                "SELECT id::text AS id, name, COALESCE(phone, '') AS phone, COALESCE(email, '') AS email
                 FROM customers
                 WHERE phone = ?
                 ORDER BY lower(name), name",
                [$phone]
            );
        }
        $people = [];
        foreach ($rows as $row) {
            $people[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'name' => RowValue::string($row['name'] ?? null),
                'phone' => RowValue::string($row['phone'] ?? null),
                'email' => RowValue::string($row['email'] ?? null),
            ];
        }

        return $people;
    }
}
