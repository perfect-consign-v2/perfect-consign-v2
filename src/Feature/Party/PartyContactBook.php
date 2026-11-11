<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\PhoneFormat;
use PerfectConsign\Shared\Scalar\RowValue;

/** Party-owned phones, emails, and physical/mailing addresses. */
final class PartyContactBook
{
    public const METHOD_PHONE = 'phone';
    public const METHOD_EMAIL = 'email';

    public const KIND_PHYSICAL = 'physical';
    public const KIND_MAILING = 'mailing';

    /** @var list<string> */
    public const LABELS = ['primary', 'mobile', 'home', 'work', 'other'];

    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * Replace all contacts for a party. Empty list clears.
     *
     * @param list<array{method: string, label?: string, value: string, is_primary?: bool}> $contacts
     */
    public function replaceContacts(string $partyId, array $contacts): void
    {
        $normalized = $this->normalizeContacts($contacts);
        $this->statements->change('DELETE FROM party_contacts WHERE party_id = ?::uuid', [$partyId]);
        foreach ($normalized as $row) {
            $this->statements->change(
                'INSERT INTO party_contacts (party_id, method, label, value, is_primary)
                 VALUES (?::uuid, ?, ?, ?, ?::boolean)',
                [$partyId, $row['method'], $row['label'], $row['value'], $row['is_primary'] ? 'true' : 'false']
            );
        }
    }

    /**
     * Upsert physical and/or mailing. Null clears that kind. Empty place fields clear.
     *
     * @param array{street?: ?string, street2?: ?string, city?: ?string, state?: ?string, postal?: ?string}|null $physical
     * @param array{street?: ?string, street2?: ?string, city?: ?string, state?: ?string, postal?: ?string}|null $mailing
     */
    public function replaceAddresses(string $partyId, ?array $physical, ?array $mailing): void
    {
        $this->writeAddress($partyId, self::KIND_PHYSICAL, $physical);
        $this->writeAddress($partyId, self::KIND_MAILING, $mailing);
    }

    public function primaryPhone(string $partyId): ?string
    {
        return $this->primaryValue($partyId, self::METHOD_PHONE);
    }

    public function primaryEmail(string $partyId): ?string
    {
        return $this->primaryValue($partyId, self::METHOD_EMAIL);
    }

    /**
     * @return array{street: ?string, street2: ?string, city: ?string, state: ?string, postal: ?string}|null
     */
    public function address(string $partyId, string $kind): ?array
    {
        if ($kind !== self::KIND_PHYSICAL && $kind !== self::KIND_MAILING) {
            throw new \InvalidArgumentException('address kind is physical or mailing');
        }
        $rows = $this->statements->bound(
            'SELECT street, street2, city, state, postal
             FROM party_addresses
             WHERE party_id = ?::uuid AND kind = ?
             LIMIT 1',
            [$partyId, $kind]
        );
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }

        return [
            'street' => RowValue::optionalString($row['street'] ?? null),
            'street2' => RowValue::optionalString($row['street2'] ?? null),
            'city' => RowValue::optionalString($row['city'] ?? null),
            'state' => RowValue::optionalString($row['state'] ?? null),
            'postal' => RowValue::optionalString($row['postal'] ?? null),
        ];
    }

    /**
     * @return list<array{method: string, label: string, value: string, is_primary: bool}>
     */
    public function contacts(string $partyId): array
    {
        $rows = $this->statements->bound(
            'SELECT method, label, value, is_primary
             FROM party_contacts
             WHERE party_id = ?::uuid
             ORDER BY method, CASE WHEN is_primary THEN 0 ELSE 1 END, label, value',
            [$partyId]
        );
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'method' => RowValue::string($row['method'] ?? null),
                'label' => RowValue::string($row['label'] ?? null),
                'value' => RowValue::string($row['value'] ?? null),
                'is_primary' => ($row['is_primary'] ?? false) === true
                    || ($row['is_primary'] ?? null) === 't'
                    || ($row['is_primary'] ?? null) === '1'
                    || ($row['is_primary'] ?? null) === true,
            ];
        }

        return $list;
    }

    /**
     * Primary phone + email as a contact list for simple person forms.
     *
     * @return list<array{method: string, label: string, value: string, is_primary: bool}>
     */
    public function primaryPair(?string $phone, ?string $email): array
    {
        $list = [];
        if ($phone !== null && $phone !== '') {
            $list[] = [
                'method' => self::METHOD_PHONE,
                'label' => 'primary',
                'value' => $phone,
                'is_primary' => true,
            ];
        }
        if ($email !== null && $email !== '') {
            $list[] = [
                'method' => self::METHOD_EMAIL,
                'label' => 'primary',
                'value' => $email,
                'is_primary' => true,
            ];
        }

        return $list;
    }

    /**
     * @param list<array{method: string, label?: string, value: string, is_primary?: bool}> $contacts
     * @return list<array{method: string, label: string, value: string, is_primary: bool}>
     */
    private function normalizeContacts(array $contacts): array
    {
        $out = [];
        $primarySeen = [self::METHOD_PHONE => false, self::METHOD_EMAIL => false];
        foreach ($contacts as $contact) {
            $method = $contact['method'];
            if ($method !== self::METHOD_PHONE && $method !== self::METHOD_EMAIL) {
                throw new \InvalidArgumentException('contact method is phone or email');
            }
            $label = $contact['label'] ?? 'primary';
            if (!in_array($label, self::LABELS, true)) {
                throw new \InvalidArgumentException('that contact label is not used');
            }
            $value = $this->normalizeValue($method, $contact['value']);
            if ($value === null) {
                continue;
            }
            $wantsPrimary = ($contact['is_primary'] ?? false) === true;
            if ($wantsPrimary && $primarySeen[$method]) {
                throw new \InvalidArgumentException('only one primary ' . $method . ' is allowed');
            }
            if ($wantsPrimary) {
                $primarySeen[$method] = true;
            }
            $out[] = [
                'method' => $method,
                'label' => $label,
                'value' => $value,
                'is_primary' => $wantsPrimary,
            ];
        }
        foreach ([self::METHOD_PHONE, self::METHOD_EMAIL] as $method) {
            if ($primarySeen[$method]) {
                continue;
            }
            foreach ($out as $index => $row) {
                if ($row['method'] === $method) {
                    $out[$index]['is_primary'] = true;
                    break;
                }
            }
        }

        return $out;
    }

    private function normalizeValue(string $method, string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if ($method === self::METHOD_PHONE) {
            $stored = PhoneFormat::normalize($trimmed);
            if ($stored === null || $stored === '') {
                throw new \InvalidArgumentException('that phone number is not valid');
            }
            if (strlen($stored) > 40) {
                throw new \InvalidArgumentException('that phone number is too long');
            }

            return $stored;
        }
        if (strlen($trimmed) > 200 || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $trimmed) !== 1) {
            throw new \InvalidArgumentException('that email is not valid');
        }

        return $trimmed;
    }

    /**
     * @param array{street?: ?string, street2?: ?string, city?: ?string, state?: ?string, postal?: ?string}|null $place
     */
    private function writeAddress(string $partyId, string $kind, ?array $place): void
    {
        $this->statements->change(
            'DELETE FROM party_addresses WHERE party_id = ?::uuid AND kind = ?',
            [$partyId, $kind]
        );
        if ($place === null) {
            return;
        }
        $street = $this->placePart($place['street'] ?? null, 200, 'that street is too long');
        $street2 = $this->placePart($place['street2'] ?? null, 200, 'that street line is too long');
        $city = $this->placePart($place['city'] ?? null, 80, 'that city is too long');
        $state = $this->placePart($place['state'] ?? null, 40, 'that state is too long');
        $postal = $this->placePart($place['postal'] ?? null, 20, 'that ZIP is too long');
        if ($street === null && $street2 === null && $city === null && $state === null && $postal === null) {
            return;
        }
        $this->statements->change(
            'INSERT INTO party_addresses (party_id, kind, street, street2, city, state, postal, is_primary)
             VALUES (?::uuid, ?, ?, ?, ?, ?, ?, true)',
            [$partyId, $kind, $street, $street2, $city, $state, $postal]
        );
    }

    private function placePart(mixed $value, int $max, string $tooLong): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (strlen($trimmed) > $max) {
            throw new \InvalidArgumentException($tooLong);
        }

        return $trimmed;
    }

    private function primaryValue(string $partyId, string $method): ?string
    {
        $rows = $this->statements->bound(
            'SELECT value FROM party_contacts
             WHERE party_id = ?::uuid AND method = ? AND is_primary
             LIMIT 1',
            [$partyId, $method]
        );

        return RowValue::optionalString($rows[0]['value'] ?? null);
    }
}
