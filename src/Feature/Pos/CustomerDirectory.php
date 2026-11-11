<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Feature\Party\PartyRoleType;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\PhoneFormat;
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
        $phone = PhoneFormat::normalize(trim($phone));
        if ($phone === '') {
            $rows = $this->statements->bound(
                "SELECT party.id::text AS id,
                        stall_party_display_name(party.id) AS name,
                        COALESCE(stall_party_primary_phone(party.id), '') AS phone,
                        COALESCE(stall_party_primary_email(party.id), '') AS email
                 FROM parties AS party
                 JOIN party_roles AS role ON role.party_id = party.id AND role.role_type = ? AND role.status = 'active'
                 ORDER BY lower(stall_party_display_name(party.id))
                 LIMIT 200",
                [PartyRoleType::CUSTOMER]
            );
        } else {
            $digits = PhoneFormat::nationalDigits($phone);
            $rows = $this->statements->bound(
                "SELECT party.id::text AS id,
                        stall_party_display_name(party.id) AS name,
                        COALESCE(stall_party_primary_phone(party.id), '') AS phone,
                        COALESCE(stall_party_primary_email(party.id), '') AS email
                 FROM parties AS party
                 JOIN party_roles AS role ON role.party_id = party.id AND role.role_type = ? AND role.status = 'active'
                 WHERE EXISTS (
                    SELECT 1
                    FROM party_contacts AS contact
                    WHERE contact.party_id = party.id
                      AND contact.method = 'phone'
                      AND right(regexp_replace(contact.value, '\D', '', 'g'), 10) = ?
                 )
                 ORDER BY lower(stall_party_display_name(party.id))",
                [PartyRoleType::CUSTOMER, $digits]
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
