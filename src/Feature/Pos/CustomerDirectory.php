<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Feature\Party\PartyRoleType;
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
            $rows = $this->statements->bound(
                "SELECT party.id::text AS id,
                        stall_party_display_name(party.id) AS name,
                        COALESCE(person.phone, org.phone, '') AS phone,
                        COALESCE(person.email, org.email, '') AS email
                 FROM parties AS party
                 JOIN party_roles AS role ON role.party_id = party.id AND role.role_type = ? AND role.status = 'active'
                 LEFT JOIN persons AS person ON person.party_id = party.id
                 LEFT JOIN organizations AS org ON org.party_id = party.id
                 ORDER BY lower(stall_party_display_name(party.id))
                 LIMIT 200",
                [PartyRoleType::CUSTOMER]
            );
        } else {
            $rows = $this->statements->bound(
                "SELECT party.id::text AS id,
                        stall_party_display_name(party.id) AS name,
                        COALESCE(person.phone, org.phone, '') AS phone,
                        COALESCE(person.email, org.email, '') AS email
                 FROM parties AS party
                 JOIN party_roles AS role ON role.party_id = party.id AND role.role_type = ? AND role.status = 'active'
                 LEFT JOIN persons AS person ON person.party_id = party.id
                 LEFT JOIN organizations AS org ON org.party_id = party.id
                 WHERE COALESCE(person.phone, org.phone, '') = ?
                 ORDER BY lower(stall_party_display_name(party.id))",
                [PartyRoleType::CUSTOMER, $phone]
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
