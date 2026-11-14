<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Shared\Database\PgBool;
use PerfectConsign\Shared\Database\Statements;
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

    private readonly PartyContactNormalizer $normalizer;

    public function __construct(
        private readonly Statements $statements,
        ?PartyContactNormalizer $normalizer = null,
    ) {
        $this->normalizer = $normalizer ?? new PartyContactNormalizer();
    }

    /**
     * Replace all contacts for a party. Empty list clears.
     *
     * @param list<array{method: string, label?: string, value: string, is_primary?: bool}> $contacts
     */
    public function replaceContacts(string $partyId, array $contacts): void
    {
        $normalized = $this->normalizer->normalize($contacts);
        $this->statements->change('DELETE FROM party_contacts WHERE party_id = ?::uuid', [$partyId]);
        foreach ($normalized as $row) {
            $this->statements->change(
                'INSERT INTO party_contacts (party_id, method, label, value, is_primary)
                 VALUES (?::uuid, ?, ?, ?, ?::boolean)',
                [$partyId, $row['method'], $row['label'], $row['value'], PgBool::literal($row['is_primary'])]
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
