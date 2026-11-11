<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Shared\Scalar\PhoneFormat;

final class VendorDraft
{
    /**
     * @param array{
     *     code: string,
     *     first_name: string,
     *     last_name: string,
     *     phone: string,
     *     email: string,
     *     address: string,
     *     city: string,
     *     state: string,
     *     postal: string,
     *     agreement_starts_on: string,
     *     agreement_ends_on: string,
     *     tax_id: string,
     *     notes: string,
     *     tax_exempt: string,
     *     tax_exempt_number: string,
     *     business_name: string,
     *     discount_by?: string,
     *     keep_days?: string,
     *     expiry_action?: string,
     *     phones?: list<string>,
     *     emails?: list<string>,
     *     mailing_address?: string,
     *     mailing_city?: string,
     *     mailing_state?: string,
     *     mailing_postal?: string,
     *     mailing_same?: string
     * } $fields
     */
    public function __construct(
        private readonly array $fields,
        private readonly ?int $commission,
    ) {
    }

    public function email(): ?string
    {
        return $this->contact($this->fields['email'], 'email');
    }

    /**
     * Flat insert values for tests and legacy callers: person contact then vendor extension facts.
     *
     * @return list<int|string|null>
     */
    public function values(): array
    {
        $person = $this->personValues();

        return [
            $this->fields['code'],
            $person['first_name'],
            $person['last_name'],
            $person['phone'],
            $person['email'],
            $person['address'],
            ...array_slice($this->vendorValues(), 1),
        ];
    }

    /**
     * @return array{
     *     first_name: string,
     *     last_name: string,
     *     display_name: ?string,
     *     phone: ?string,
     *     email: ?string,
     *     address: ?string,
     *     city: ?string,
     *     state: ?string,
     *     postal: ?string
     * }
     */
    public function personValues(): array
    {
        $physical = $this->physicalAddress();

        return [
            'first_name' => $this->personName($this->fields['first_name'], 'first'),
            'last_name' => $this->personName($this->fields['last_name'], 'last'),
            'display_name' => $this->optionalText($this->fields['business_name'] ?? '', 120, 'that business name is too long'),
            'phone' => $this->contact($this->fields['phone'], 'phone'),
            'email' => $this->contact($this->fields['email'], 'email'),
            'address' => $physical['street'] ?? null,
            'city' => $physical['city'] ?? null,
            'state' => $physical['state'] ?? null,
            'postal' => $physical['postal'] ?? null,
        ];
    }

    /**
     * @return list<array{method: string, label: string, value: string, is_primary: bool}>
     */
    public function contacts(): array
    {
        $list = [];
        $primaryPhone = $this->contact($this->fields['phone'], 'phone');
        if ($primaryPhone !== null) {
            $list[] = [
                'method' => PartyContactBook::METHOD_PHONE,
                'label' => 'primary',
                'value' => $primaryPhone,
                'is_primary' => true,
            ];
        }
        foreach ($this->extraList($this->fields['phones'] ?? []) as $extra) {
            $phone = $this->contact($extra, 'phone');
            if ($phone === null) {
                continue;
            }
            $list[] = [
                'method' => PartyContactBook::METHOD_PHONE,
                'label' => 'other',
                'value' => $phone,
                'is_primary' => false,
            ];
        }
        $primaryEmail = $this->contact($this->fields['email'], 'email');
        if ($primaryEmail !== null) {
            $list[] = [
                'method' => PartyContactBook::METHOD_EMAIL,
                'label' => 'primary',
                'value' => $primaryEmail,
                'is_primary' => true,
            ];
        }
        foreach ($this->extraList($this->fields['emails'] ?? []) as $extra) {
            $mail = $this->contact($extra, 'email');
            if ($mail === null) {
                continue;
            }
            $list[] = [
                'method' => PartyContactBook::METHOD_EMAIL,
                'label' => 'other',
                'value' => $mail,
                'is_primary' => false,
            ];
        }

        return $list;
    }

    /**
     * @return array{street: ?string, street2: ?string, city: ?string, state: ?string, postal: ?string}|null
     */
    public function physicalAddress(): ?array
    {
        return $this->placeFrom(
            $this->fields['address'],
            $this->fields['city'] ?? '',
            $this->fields['state'] ?? '',
            $this->fields['postal'] ?? '',
        );
    }

    /**
     * @return array{street: ?string, street2: ?string, city: ?string, state: ?string, postal: ?string}|null
     */
    public function mailingAddress(): ?array
    {
        if (($this->fields['mailing_same'] ?? '') === '1' || ($this->fields['mailing_same'] ?? '') === 'true') {
            return $this->physicalAddress();
        }

        return $this->placeFrom(
            $this->fields['mailing_address'] ?? '',
            $this->fields['mailing_city'] ?? '',
            $this->fields['mailing_state'] ?? '',
            $this->fields['mailing_postal'] ?? '',
        );
    }

    /**
     * Vendor extension columns only (party identity is separate).
     *
     * @return list<int|string|null>
     */
    public function vendorValues(): array
    {
        $facts = $this->identityFacts();

        return [
            $this->fields['code'],
            $facts[0],
            $facts[1],
            $facts[2],
            $facts[3],
            $facts[4],
            $facts[5] ? 'true' : 'false',
            $facts[6],
            ...$this->terms(),
        ];
    }

    /**
     * @return array{0: ?int, 1: ?string, 2: ?string, 3: ?string, 4: ?string, 5: bool, 6: ?string}
     */
    private function identityFacts(): array
    {
        $start = $this->day($this->fields['agreement_starts_on']);
        $end = $this->day($this->fields['agreement_ends_on']);
        if ($start !== null && $end !== null && $start > $end) {
            throw new \InvalidArgumentException('an agreement end cannot be before its start');
        }
        $number = $this->optionalText($this->fields['tax_exempt_number'], 100, 'that resale number is too long');
        $exempt = $this->fields['tax_exempt'] === '1' || $this->fields['tax_exempt'] === 'true';
        if ($exempt && $number === null) {
            throw new \InvalidArgumentException('Tax exempt number is required when tax exempt is enabled');
        }

        return [
            $this->commission,
            $start,
            $end,
            $this->optionalText($this->fields['tax_id'], 40, 'that tax id is too long'),
            $this->optionalText($this->fields['notes'], 2000, 'those notes are too long'),
            $exempt,
            $number,
        ];
    }

    /**
     * @return array{0: string, 1: ?int, 2: string}
     */
    private function terms(): array
    {
        return [
            $this->choice('discount_by', 'staff', ['staff', 'owner'], 'who may discount is staff or owner'),
            $this->keepDays(),
            $this->choice('expiry_action', 'return', ['return', 'donate', 'buy'], 'expiry is return, donate, or buy'),
        ];
    }

    /**
     * @param list<string> $allowed
     */
    private function choice(string $key, string $fallback, array $allowed, string $message): string
    {
        $value = $this->fields[$key] ?? $fallback;
        if ($value === '') {
            $value = $fallback;
        }
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException($message);
        }

        return $value;
    }

    private function keepDays(): ?int
    {
        $days = trim($this->fields['keep_days'] ?? '');
        if ($days === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $days) !== 1) {
            throw new \InvalidArgumentException('how long goods stay is a number of days');
        }
        $kept = (int) $days;
        if ($kept < 1 || $kept > 3650) {
            throw new \InvalidArgumentException('how long goods stay is a number of days');
        }

        return $kept;
    }

    private function day(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) !== 1) {
            throw new \InvalidArgumentException('an agreement date is YYYY-MM-DD');
        }
        $year = (int) substr($trimmed, 0, 4);
        $month = (int) substr($trimmed, 5, 2);
        $day = (int) substr($trimmed, 8, 2);
        if (!checkdate($month, $day, $year)) {
            throw new \InvalidArgumentException('an agreement date is YYYY-MM-DD');
        }

        return $trimmed;
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

    private function personName(string $value, string $part): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('a ' . $part . ' name is required');
        }

        return $trimmed;
    }

    private function contact(string $value, string $kind): ?string
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
     * @param list<mixed> $values
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
}
