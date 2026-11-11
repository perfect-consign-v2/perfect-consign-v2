<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Feature\Party\PartyIdentity;
use PerfectConsign\Feature\Party\PartyRoleType;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\PhoneFormat;
use PerfectConsign\Shared\Scalar\RowValue;

final class CustomerStore
{
    public function __construct(
        private readonly Statements $statements,
        private readonly PartyIdentity $identity,
    ) {
    }

    /** @return string party id */
    public function findOrCreate(string $name, string $phone, string $email): string
    {
        $name = trim($name);
        $phone = PhoneFormat::normalize(trim($phone));
        $email = trim($email);
        $this->accept($name, $phone, $email);
        $phoneDigits = PhoneFormat::nationalDigits($phone);
        $existing = $this->statements->bound(
            "SELECT party.id::text AS id
             FROM parties AS party
             JOIN party_roles AS role ON role.party_id = party.id AND role.role_type = ? AND role.status = 'active'
             WHERE lower(stall_party_display_name(party.id)) = lower(?)
               AND (
                    (? = '' AND COALESCE(stall_party_primary_phone(party.id), '') = '')
                    OR (
                        ? <> ''
                        AND right(regexp_replace(COALESCE(stall_party_primary_phone(party.id), ''), '\D', '', 'g'), 10) = ?
                    )
               )
               AND COALESCE(stall_party_primary_email(party.id), '') = ?
             ORDER BY party.created_at
             LIMIT 1",
            [PartyRoleType::CUSTOMER, $name, $phoneDigits, $phoneDigits, $phoneDigits, $email]
        );
        $found = $existing[0]['id'] ?? null;
        if (is_string($found) && $found !== '') {
            return $found;
        }

        $partyId = '';
        $this->statements->transaction(function () use ($name, $phone, $email, &$partyId): void {
            [$first, $last] = $this->personNames($name);
            $created = $this->identity->createPerson(
                $first,
                $last,
                $name === trim($first . ' ' . $last) ? null : $name,
                $phone === '' ? null : $phone,
                $email === '' ? null : $email,
                null,
                PartyRoleType::CUSTOMER,
            );
            $partyId = $created['party_id'];
        });

        return $partyId;
    }

    public function save(
        string $partyId,
        string $name,
        string $phone,
        string $email,
    ): void {
        $name = trim($name);
        $phone = PhoneFormat::normalize(trim($phone));
        $email = trim($email);
        $this->accept($name, $phone, $email);
        if (!$this->identity->hasActiveRole($partyId, PartyRoleType::CUSTOMER)) {
            throw new DatabaseRefusal('that customer is not in this mall');
        }
        [$first, $last] = $this->personNames($name);
        $person = $this->statements->bound(
            'SELECT party_id FROM persons WHERE party_id = ?::uuid LIMIT 1',
            [$partyId]
        );
        if ($person === []) {
            throw new DatabaseRefusal('Organization customers cannot be edited as a person name yet.');
        }
        $place = $this->identity->place($partyId) ?? [
            'address' => null,
            'city' => null,
            'state' => null,
            'postal' => null,
        ];
        $this->identity->savePerson(
            $partyId,
            $first,
            $last,
            $name === trim($first . ' ' . $last) ? null : $name,
            $phone === '' ? null : $phone,
            $email === '' ? null : $email,
            $place['address'],
            $place['city'],
            $place['state'],
            $place['postal'],
        );
    }

    /**
     * @return array{id: string, name: string, phone: string, email: string}|null
     */
    public function find(string $partyId): ?array
    {
        $rows = $this->statements->bound(
            "SELECT party.id::text AS id,
                    stall_party_display_name(party.id) AS name,
                    COALESCE(stall_party_primary_phone(party.id), '') AS phone,
                    COALESCE(stall_party_primary_email(party.id), '') AS email
             FROM parties AS party
             JOIN party_roles AS role ON role.party_id = party.id AND role.role_type = ? AND role.status = 'active'
             WHERE party.id = ?::uuid
             LIMIT 1",
            [PartyRoleType::CUSTOMER, $partyId]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => RowValue::string($row['id'] ?? null),
            'name' => RowValue::string($row['name'] ?? null),
            'phone' => RowValue::string($row['phone'] ?? null),
            'email' => RowValue::string($row['email'] ?? null),
        ];
    }

    public function addVendorRole(string $partyId, string $code): string
    {
        $customer = $this->find($partyId);
        if ($customer === null) {
            throw new DatabaseRefusal('that customer is not in this mall');
        }
        $person = $this->statements->bound(
            'SELECT first_name, last_name, display_name FROM persons WHERE party_id = ?::uuid',
            [$partyId]
        );
        if ($person === []) {
            throw new DatabaseRefusal('Organization customers cannot be promoted to vendor.');
        }
        $vendorId = '';
        $this->statements->transaction(function () use ($partyId, $code, &$vendorId): void {
            $this->identity->ensureRole($partyId, PartyRoleType::VENDOR);
            $existing = $this->statements->bound(
                'SELECT id::text AS id FROM vendors WHERE party_id = ?::uuid LIMIT 1',
                [$partyId]
            );
            $id = $existing[0]['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $vendorId = $id;

                return;
            }
            $rows = $this->statements->bound(
                'INSERT INTO vendors (party_id, code) VALUES (?::uuid, ?) RETURNING id',
                [$partyId, $code]
            );
            $vendorId = RowValue::string($rows[0]['id'] ?? null);
        });

        return $vendorId;
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

    /**
     * @return array{0: string, 1: string}
     */
    private function personNames(string $name): array
    {
        $space = strpos($name, ' ');
        if ($space === false) {
            return [$name, $name];
        }
        $first = substr($name, 0, $space);
        $last = trim(substr($name, $space + 1));
        if ($first === '' || $last === '') {
            return [$name, $name];
        }

        return [$first, $last];
    }
}
