<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

class PartyStore
{
    private readonly PartyIdentity $identity;

    private readonly PartyContactBook $contacts;

    public function __construct(
        private readonly Statements $statements,
        private readonly JournalAudit $audit,
        private readonly AssignmentBook $assignments,
        ?PartyIdentity $identity = null,
        ?PartyContactBook $contacts = null,
    ) {
        $this->identity = $identity ?? new PartyIdentity($statements);
        $this->contacts = $contacts ?? new PartyContactBook($statements);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function vendors(): array
    {
        return $this->statements->rows(
            "SELECT vendor.id, vendor.code, vendor.name, vendor.party_id,
                    person.first_name, person.last_name, person.display_name AS business_name,
                    stall_party_primary_phone(vendor.party_id) AS phone,
                    stall_party_primary_email(vendor.party_id) AS email,
                    physical.street AS address, physical.city, physical.state, physical.postal
             FROM vendors AS vendor
             JOIN persons AS person ON person.party_id = vendor.party_id
             LEFT JOIN party_addresses AS physical
               ON physical.party_id = vendor.party_id AND physical.kind = 'physical'
             ORDER BY vendor.name, vendor.code"
        );
    }

    /**
     * Parties with any role (identity hub).
     *
     * @return list<array{id: string, name: string, kind: string, roles: list<string>}>
     */
    public function parties(): array
    {
        $rows = $this->statements->rows(
            "SELECT party.id::text AS id,
                    COALESCE(stall_party_display_name(party.id), '') AS name,
                    CASE WHEN person.party_id IS NOT NULL THEN 'person' ELSE 'organization' END AS kind,
                    COALESCE(string_agg(roles.role_type, ', ' ORDER BY roles.role_type), '') AS roles
             FROM parties AS party
             LEFT JOIN persons AS person ON person.party_id = party.id
             LEFT JOIN organizations AS org ON org.party_id = party.id
             LEFT JOIN party_roles AS roles ON roles.party_id = party.id AND roles.status = 'active'
             GROUP BY party.id, person.party_id, org.party_id
             ORDER BY lower(COALESCE(stall_party_display_name(party.id), '')), party.id
             LIMIT 500"
        );
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'name' => RowValue::string($row['name'] ?? null),
                'kind' => RowValue::string($row['kind'] ?? null),
                'roles' => PartyRoleType::fromAgg(RowValue::string($row['roles'] ?? null)),
            ];
        }

        return $list;
    }

    /**
     * @return array{id: string, name: string, kind: string, roles: list<string>}|null
     */
    public function findParty(string $partyId): ?array
    {
        $rows = $this->statements->bound(
            "SELECT party.id::text AS id,
                    COALESCE(stall_party_display_name(party.id), '') AS name,
                    CASE WHEN person.party_id IS NOT NULL THEN 'person' ELSE 'organization' END AS kind
             FROM parties AS party
             LEFT JOIN persons AS person ON person.party_id = party.id
             WHERE party.id = ?::uuid",
            [$partyId]
        );
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }
        $roles = [];
        foreach ($this->statements->bound(
            'SELECT role_type FROM party_roles WHERE party_id = ?::uuid ORDER BY role_type',
            [$partyId]
        ) as $roleRow) {
            $role = $roleRow['role_type'] ?? null;
            if (is_string($role) && $role !== '') {
                $roles[] = PartyRoleType::assertStored($role);
            }
        }

        return [
            'id' => RowValue::string($row['id'] ?? null),
            'name' => RowValue::string($row['name'] ?? null),
            'kind' => RowValue::string($row['kind'] ?? null),
            'roles' => $roles,
        ];
    }

    public function vendorIdForParty(string $partyId): ?string
    {
        $rows = $this->statements->bound(
            'SELECT id::text AS id FROM vendors WHERE party_id = ?::uuid LIMIT 1',
            [$partyId]
        );
        $id = $rows[0]['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function booths(): array
    {
        return $this->statements->rows(
            'SELECT id, code, name, width_ft, depth_ft, x_ft, z_ft, floor_id FROM booths ORDER BY code'
        );
    }

    public function addVendor(VendorDraft $vendor): string
    {
        $id = '';
        $this->statements->transaction(function () use ($vendor, &$id): void {
            $person = $vendor->personValues();
            $created = $this->identity->createPerson(
                $person['first_name'],
                $person['last_name'],
                $person['display_name'],
                null,
                null,
                null,
                PartyRoleType::VENDOR,
            );
            $partyId = $created['party_id'];
            $this->contacts->replaceContacts($partyId, $vendor->contacts());
            $this->contacts->replaceAddresses($partyId, $vendor->physicalAddress(), $vendor->mailingAddress());
            $rows = $this->statements->bound(
                'INSERT INTO vendors (
                    party_id, code, commission_bps, agreement_starts_on, agreement_ends_on, tax_id, notes,
                    tax_exempt, tax_exempt_number, discount_by, keep_days, expiry_action
                 ) VALUES (?::uuid, ?, ?, ?::date, ?::date, ?, ?, ?::boolean, ?, ?, ?, ?) RETURNING id',
                [$partyId, ...$vendor->vendorValues()]
            );
            $saved = $rows[0]['id'] ?? null;
            if (!is_string($saved) || $saved === '') {
                throw new \InvalidArgumentException('the vendor was not saved');
            }
            $id = $saved;
        });

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findVendor(string $id): ?array
    {
        $rows = $this->statements->bound(
            "SELECT vendor.id, vendor.party_id, vendor.code, vendor.name, vendor.active,
                    person.first_name, person.last_name, person.display_name AS business_name,
                    stall_party_primary_phone(vendor.party_id) AS phone,
                    stall_party_primary_email(vendor.party_id) AS email,
                    physical.street AS address, physical.city, physical.state, physical.postal,
                    mailing.street AS mailing_address, mailing.city AS mailing_city,
                    mailing.state AS mailing_state, mailing.postal AS mailing_postal,
                    vendor.commission_bps, vendor.agreement_starts_on, vendor.agreement_ends_on, vendor.tax_id, vendor.notes,
                    vendor.tax_exempt, vendor.tax_exempt_number, vendor.payout_mode,
                    vendor.discount_by, vendor.keep_days, vendor.expiry_action, vendor.email_bounced
             FROM vendors AS vendor
             JOIN persons AS person ON person.party_id = vendor.party_id
             LEFT JOIN party_addresses AS physical
               ON physical.party_id = vendor.party_id AND physical.kind = 'physical'
             LEFT JOIN party_addresses AS mailing
               ON mailing.party_id = vendor.party_id AND mailing.kind = 'mailing'
             WHERE vendor.id = ?::uuid",
            [$id]
        );
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }
        $partyId = RowValue::string($row['party_id'] ?? null);
        $row['phones'] = [];
        $row['emails'] = [];
        foreach ($this->contacts->contacts($partyId) as $contact) {
            if ($contact['is_primary']) {
                continue;
            }
            if ($contact['method'] === PartyContactBook::METHOD_PHONE) {
                $row['phones'][] = $contact['value'];
            }
            if ($contact['method'] === PartyContactBook::METHOD_EMAIL) {
                $row['emails'][] = $contact['value'];
            }
        }

        return $row;
    }

    public function saveVendor(string $id, VendorDraft $vendor): void
    {
        $this->statements->transaction(function () use ($id, $vendor): void {
            $current = $this->findVendor($id);
            if ($current === null) {
                throw new \InvalidArgumentException('that vendor is not in this mall');
            }
            $partyId = RowValue::string($current['party_id'] ?? null);
            $person = $vendor->personValues();
            $this->identity->savePersonNames(
                $partyId,
                $person['first_name'],
                $person['last_name'],
                $person['display_name'],
            );
            $this->contacts->replaceContacts($partyId, $vendor->contacts());
            $this->contacts->replaceAddresses($partyId, $vendor->physicalAddress(), $vendor->mailingAddress());
            $priorEmail = RowValue::string($current['email'] ?? null);
            $nextEmail = $person['email'] ?? '';
            $count = $this->statements->affected(
                "UPDATE vendors
                 SET code = ?, commission_bps = ?, agreement_starts_on = ?::date, agreement_ends_on = ?::date,
                     tax_id = ?, notes = ?, tax_exempt = ?::boolean, tax_exempt_number = ?,
                     discount_by = ?, keep_days = ?, expiry_action = ?,
                     email_bounced = CASE
                         WHEN lower(COALESCE(?, '')) IS DISTINCT FROM lower(COALESCE(?::text, '')) THEN false
                         ELSE email_bounced
                     END
                 WHERE id = ?::uuid",
                [...$vendor->vendorValues(), $priorEmail, $nextEmail, $id]
            );
            if ($count === 0) {
                throw new \InvalidArgumentException('that vendor is not in this mall');
            }
            $this->audit->record('vendor.edit', $id, 'vendor');
        });
    }

    public function setPayoutMode(string $id, string $mode): void
    {
        $stored = in_array($mode, ['pay', 'credit_only', 'hold'], true) ? $mode : 'pay';
        $count = $this->statements->affected(
            'UPDATE vendors SET payout_mode = ? WHERE id = ?::uuid',
            [$stored, $id]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('that vendor is not in this mall');
        }
    }

    public function setActive(string $id, bool $active): void
    {
        $this->statements->transaction(function () use ($id, $active): void {
            $count = $this->statements->affected(
                'UPDATE vendors SET active = ?::boolean WHERE id = ?::uuid',
                [$active ? 'true' : 'false', $id]
            );
            if ($count === 0) {
                throw new \InvalidArgumentException('that vendor is not in this mall');
            }
            $vendor = $this->findVendor($id);
            $partyId = RowValue::string($vendor['party_id'] ?? null);
            if ($partyId !== '') {
                $this->statements->change(
                    "UPDATE party_roles SET status = ? WHERE party_id = ?::uuid AND role_type = ?",
                    [$active ? 'active' : 'inactive', $partyId, PartyRoleType::VENDOR]
                );
            }
            if ($active) {
                return;
            }
            $this->statements->change(
                "UPDATE staff
                 SET credentials_version = credentials_version + 1
                 WHERE role = 'vendor' AND vendor_id = ?::uuid",
                [$id]
            );
        });
    }

    public function removeVendor(string $id): void
    {
        $count = $this->statements->affected('DELETE FROM vendors WHERE id = ?::uuid', [$id]);
        if ($count === 0) {
            throw new \InvalidArgumentException('that vendor is not in this mall');
        }
    }

    public function addCustomerRole(string $vendorId): string
    {
        $vendor = $this->findVendor($vendorId);
        if ($vendor === null) {
            throw new \InvalidArgumentException('that vendor is not in this mall');
        }
        $partyId = RowValue::string($vendor['party_id'] ?? null);
        $this->identity->ensureRole($partyId, PartyRoleType::CUSTOMER);

        return $partyId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBooth(string $id): ?array
    {
        $rows = $this->statements->bound(
            'SELECT id, code, name, width_ft, depth_ft, x_ft, z_ft, floor_id FROM booths WHERE id = ?::uuid',
            [$id]
        );

        return $rows[0] ?? null;
    }

    public function addBooth(
        string $code,
        string $name,
        ?int $width,
        ?int $depth,
        ?int $amount = null,
    ): void {
        $insert = function () use ($code, $name, $width, $depth, $amount): void {
            $rows = $this->statements->bound(
                'INSERT INTO booths (code, name, width_ft, depth_ft) VALUES (?, ?, ?, ?) RETURNING id',
                [$code, $name, $width, $depth]
            );
            $id = $rows[0]['id'] ?? null;
            if (!is_string($id) || $id === '') {
                throw new \InvalidArgumentException('that booth was not created');
            }
            if ($amount !== null) {
                $this->assignments->setAskingRent($id, $amount);
            }
        };
        if ($amount === null) {
            $insert();

            return;
        }
        $this->statements->transaction($insert);
    }

    public function saveBooth(string $id, string $code, string $name, ?int $width, ?int $depth): void
    {
        $count = $this->statements->affected(
            'UPDATE booths SET code = ?, name = ?, width_ft = ?, depth_ft = ? WHERE id = ?::uuid',
            [$code, $name, $width, $depth, $id]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('that booth is not in this mall');
        }
    }

    public function removeBooth(string $id): void
    {
        $count = $this->statements->affected('DELETE FROM booths WHERE id = ?::uuid', [$id]);
        if ($count === 0) {
            throw new \InvalidArgumentException('that booth is not in this mall');
        }
    }
}
