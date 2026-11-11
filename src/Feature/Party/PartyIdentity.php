<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/** Creates party + person (+ role) in one place; contact lives on party children. */
final class PartyIdentity
{
    private readonly PartyContactBook $contacts;

    public function __construct(
        private readonly Statements $statements,
        ?PartyContactBook $contacts = null,
    ) {
        $this->contacts = $contacts ?? new PartyContactBook($statements);
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
        ?string $city = null,
        ?string $state = null,
        ?string $postal = null,
    ): array {
        $partyRows = $this->statements->rows('INSERT INTO parties DEFAULT VALUES RETURNING id');
        $partyId = RowValue::string($partyRows[0]['id'] ?? null);
        if ($partyId === '') {
            throw new \InvalidArgumentException('the party was not saved');
        }
        $this->statements->change(
            'INSERT INTO persons (party_id, first_name, last_name, display_name)
             VALUES (?::uuid, ?, ?, ?)',
            [$partyId, $firstName, $lastName, $displayName]
        );
        $this->contacts->replaceContacts($partyId, $this->contacts->primaryPair($phone, $email));
        $this->contacts->replaceAddresses(
            $partyId,
            $this->physicalPlace($address, $city, $state, $postal),
            null,
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
        ?string $city = null,
        ?string $state = null,
        ?string $postal = null,
    ): void {
        $count = $this->statements->affected(
            'UPDATE persons
             SET first_name = ?, last_name = ?, display_name = ?
             WHERE party_id = ?::uuid',
            [$firstName, $lastName, $displayName, $partyId]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('that person is not in this mall');
        }
        $this->contacts->replaceContacts($partyId, $this->contacts->primaryPair($phone, $email));
        $this->contacts->replaceAddresses(
            $partyId,
            $this->physicalPlace($address, $city, $state, $postal),
            $this->contacts->address($partyId, PartyContactBook::KIND_MAILING),
        );
    }

    /**
     * Names only; contact lists and addresses are separate.
     */
    public function savePersonNames(
        string $partyId,
        string $firstName,
        string $lastName,
        ?string $displayName,
    ): void {
        $count = $this->statements->affected(
            'UPDATE persons
             SET first_name = ?, last_name = ?, display_name = ?
             WHERE party_id = ?::uuid',
            [$firstName, $lastName, $displayName, $partyId]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('that person is not in this mall');
        }
    }

    /**
     * @return array{address: ?string, city: ?string, state: ?string, postal: ?string}|null
     */
    public function place(string $partyId): ?array
    {
        $physical = $this->contacts->address($partyId, PartyContactBook::KIND_PHYSICAL);
        if ($physical === null) {
            return null;
        }

        return [
            'address' => $physical['street'],
            'city' => $physical['city'],
            'state' => $physical['state'],
            'postal' => $physical['postal'],
        ];
    }

    public function contacts(): PartyContactBook
    {
        return $this->contacts;
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

    /**
     * @return array{street: ?string, street2: ?string, city: ?string, state: ?string, postal: ?string}|null
     */
    private function physicalPlace(
        ?string $address,
        ?string $city,
        ?string $state,
        ?string $postal,
    ): ?array {
        if (
            ($address === null || $address === '')
            && ($city === null || $city === '')
            && ($state === null || $state === '')
            && ($postal === null || $postal === '')
        ) {
            return null;
        }

        return [
            'street' => $address,
            'street2' => null,
            'city' => $city,
            'state' => $state,
            'postal' => $postal,
        ];
    }
}
