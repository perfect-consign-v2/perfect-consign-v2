<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Shared\Database\PgBool;

final class VendorDraft
{
    private readonly VendorDraftPlaces $places;

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
        ?VendorDraftPlaces $places = null,
    ) {
        $this->places = $places ?? new VendorDraftPlaces();
    }

    public function email(): ?string
    {
        return $this->places->contact($this->fields['email'], 'email');
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
            'phone' => $this->places->contact($this->fields['phone'], 'phone'),
            'email' => $this->places->contact($this->fields['email'], 'email'),
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
        return $this->places->contacts($this->fields);
    }

    /**
     * @return array{street: ?string, street2: ?string, city: ?string, state: ?string, postal: ?string}|null
     */
    public function physicalAddress(): ?array
    {
        return $this->places->physical($this->fields);
    }

    /**
     * @return array{street: ?string, street2: ?string, city: ?string, state: ?string, postal: ?string}|null
     */
    public function mailingAddress(): ?array
    {
        return $this->places->mailing($this->fields);
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
            PgBool::literal($facts[5] === true),
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
}
