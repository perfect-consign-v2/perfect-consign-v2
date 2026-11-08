<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/** Creates party + person (+ role) in one place. */
final class PartyIdentity
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return array{party_id: string}
     */
    public function createPerson(
        string $firstName,
        string $lastName,
        ?string $displayName,
        ?string $phone,
        ?string $email,
        ?string $address,
        string $roleType,
    ): array {
        $partyRows = $this->statements->rows('INSERT INTO parties DEFAULT VALUES RETURNING id');
        $partyId = RowValue::string($partyRows[0]['id'] ?? null);
        if ($partyId === '') {
            throw new \InvalidArgumentException('the party was not saved');
        }
        $this->statements->change(
            'INSERT INTO persons (party_id, first_name, last_name, display_name, phone, email, address)
             VALUES (?::uuid, ?, ?, ?, ?, ?, ?)',
            [$partyId, $firstName, $lastName, $displayName, $phone, $email, $address]
        );
        $this->statements->change(
            'INSERT INTO party_roles (party_id, role_type, status) VALUES (?::uuid, ?, \'active\')',
            [$partyId, $roleType]
        );

        return ['party_id' => $partyId];
    }

    public function savePerson(
        string $partyId,
        string $firstName,
        string $lastName,
        ?string $displayName,
        ?string $phone,
        ?string $email,
        ?string $address,
    ): void {
        $count = $this->statements->affected(
            'UPDATE persons
             SET first_name = ?, last_name = ?, display_name = ?, phone = ?, email = ?, address = ?
             WHERE party_id = ?::uuid',
            [$firstName, $lastName, $displayName, $phone, $email, $address, $partyId]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('that person is not in this mall');
        }
    }

    public function ensureRole(string $partyId, string $roleType): void
    {
        $existing = $this->statements->bound(
            'SELECT id, status FROM party_roles WHERE party_id = ?::uuid AND role_type = ? LIMIT 1',
            [$partyId, $roleType]
        );
        $id = $existing[0]['id'] ?? null;
        if (is_string($id) && $id !== '') {
            $status = RowValue::string($existing[0]['status'] ?? null);
            if ($status !== 'active') {
                $this->statements->change(
                    'UPDATE party_roles SET status = \'active\' WHERE id = ?::uuid',
                    [$id]
                );
            }

            return;
        }
        $this->statements->change(
            'INSERT INTO party_roles (party_id, role_type, status) VALUES (?::uuid, ?, \'active\')',
            [$partyId, $roleType]
        );
    }

    public function hasActiveRole(string $partyId, string $roleType): bool
    {
        $rows = $this->statements->bound(
            "SELECT 1 FROM party_roles WHERE party_id = ?::uuid AND role_type = ? AND status = 'active' LIMIT 1",
            [$partyId, $roleType]
        );

        return $rows !== [];
    }
}
