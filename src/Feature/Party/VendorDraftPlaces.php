<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Shared\Scalar\PhoneFormat;

/** Contact list and address shaping for VendorDraft. */
final class VendorDraftPlaces
{
    /**
     * @param array<string, mixed> $fields
     * @return list<array{method: string, label: string, value: string, is_primary: bool}>
     */
    public function contacts(array $fields): array
    {
        return [
            ...$this->methodContacts(
                PartyContactBook::METHOD_PHONE,
                is_string($fields['phone'] ?? null) ? $fields['phone'] : '',
                is_array($fields['phones'] ?? null) ? $fields['phones'] : [],
                'phone',
            ),
            ...$this->methodContacts(
                PartyContactBook::METHOD_EMAIL,
                is_string($fields['email'] ?? null) ? $fields['email'] : '',
                is_array($fields['emails'] ?? null) ? $fields['emails'] : [],
                'email',
            ),
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{street: ?string, street2: ?string, city: ?string, state: ?string, postal: ?string}|null
     */
    public function physical(array $fields): ?array
    {
        return $this->placeFrom(
            is_string($fields['address'] ?? null) ? $fields['address'] : '',
            is_string($fields['city'] ?? null) ? $fields['city'] : '',
            is_string($fields['state'] ?? null) ? $fields['state'] : '',
            is_string($fields['postal'] ?? null) ? $fields['postal'] : '',
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{street: ?string, street2: ?string, city: ?string, state: ?string, postal: ?string}|null
     */
    public function mailing(array $fields): ?array
    {
        if (($fields['mailing_same'] ?? '') === '1' || ($fields['mailing_same'] ?? '') === 'true') {
            return $this->physical($fields);
        }

        return $this->placeFrom(
            is_string($fields['mailing_address'] ?? null) ? $fields['mailing_address'] : '',
            is_string($fields['mailing_city'] ?? null) ? $fields['mailing_city'] : '',
            is_string($fields['mailing_state'] ?? null) ? $fields['mailing_state'] : '',
            is_string($fields['mailing_postal'] ?? null) ? $fields['mailing_postal'] : '',
        );
    }

    public function contact(string $value, string $kind): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if ($kind === 'phone') {
            $trimmed = PhoneFormat::normalize($trimmed);
            if (strlen($trimmed) > 40) {
                throw new \InvalidArgumentException('that phone is too long');
            }

            return $trimmed;
        }
        if ($kind === 'email' && (strlen($trimmed) > 200 || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $trimmed) !== 1)) {
            throw new \InvalidArgumentException('that email is not an email address');
        }
        if ($kind === 'address' && strlen($trimmed) > 200) {
            throw new \InvalidArgumentException('that address is too long');
        }

        return $trimmed;
    }

    /**
     * @param array<mixed> $extras
     * @return list<array{method: string, label: string, value: string, is_primary: bool}>
     */
    private function methodContacts(string $method, string $primary, array $extras, string $kind): array
    {
        $list = [];
        $primaryValue = $this->contact($primary, $kind);
        if ($primaryValue !== null) {
            $list[] = [
                'method' => $method,
                'label' => 'primary',
                'value' => $primaryValue,
                'is_primary' => true,
            ];
        }
        foreach ($this->extraList($extras) as $extra) {
            $value = $this->contact($extra, $kind);
            if ($value === null) {
                continue;
            }
            $list[] = [
                'method' => $method,
                'label' => 'other',
                'value' => $value,
                'is_primary' => false,
            ];
        }

        return $list;
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function extraList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return $out;
    }

    /**
     * @return array{street: ?string, street2: ?string, city: ?string, state: ?string, postal: ?string}|null
     */
    private function placeFrom(string $street, string $city, string $state, string $postal): ?array
    {
        $line = $this->contact($street, 'address');
        $cityPart = $this->optionalText($city, 80, 'that city is too long');
        $statePart = $this->optionalText($state, 40, 'that state is too long');
        $postalPart = $this->optionalText($postal, 20, 'that ZIP is too long');
        if ($line === null && $cityPart === null && $statePart === null && $postalPart === null) {
            return null;
        }

        return [
            'street' => $line,
            'street2' => null,
            'city' => $cityPart,
            'state' => $statePart,
            'postal' => $postalPart,
        ];
    }

    private function optionalText(string $value, int $limit, string $tooLong): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (strlen($trimmed) > $limit) {
            throw new \InvalidArgumentException($tooLong);
        }

        return $trimmed;
    }
}
