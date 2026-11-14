<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Shared\Scalar\PhoneFormat;

/** Validates and normalizes party contact rows before replace. */
final class PartyContactNormalizer
{
    private bool $replacePhone = false;

    private ?string $storedPhone = null;

    public function standInStoredPhone(?string $stored): void
    {
        $this->replacePhone = true;
        $this->storedPhone = $stored;
    }
    /**
     * @param list<array{method: string, label?: string, value: string, is_primary?: bool}> $contacts
     * @return list<array{method: 'email'|'phone', label: string, value: string, is_primary: bool}>
     */
    public function normalize(array $contacts): array
    {
        $out = [];
        $primarySeen = [
            'phone' => false,
            'email' => false,
        ];
        foreach ($contacts as $contact) {
            $row = $this->row($contact, $primarySeen);
            if ($row === null) {
                continue;
            }
            if ($row['is_primary']) {
                $primarySeen[$row['method']] = true;
            }
            $out[] = $row;
        }

        return $this->ensurePrimary($out, $primarySeen);
    }

    /**
     * @param array{method: string, label?: string, value: string, is_primary?: bool} $contact
     * @param array{phone: bool, email: bool} $primarySeen
     * @return array{method: 'email'|'phone', label: string, value: string, is_primary: bool}|null
     */
    private function row(array $contact, array $primarySeen): ?array
    {
        $method = $contact['method'];
        if ($method !== PartyContactBook::METHOD_PHONE && $method !== PartyContactBook::METHOD_EMAIL) {
            throw new \InvalidArgumentException('contact method is phone or email');
        }
        $label = $contact['label'] ?? 'primary';
        if (!in_array($label, PartyContactBook::LABELS, true)) {
            throw new \InvalidArgumentException('that contact label is not used');
        }
        $value = $this->value($method, $contact['value']);
        if ($value === null) {
            return null;
        }
        $wantsPrimary = ($contact['is_primary'] ?? false) === true;
        if ($wantsPrimary && $primarySeen[$method]) {
            throw new \InvalidArgumentException('only one primary ' . $method . ' is allowed');
        }

        return [
            'method' => $method,
            'label' => $label,
            'value' => $value,
            'is_primary' => $wantsPrimary,
        ];
    }

    private function value(string $method, string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if ($method === PartyContactBook::METHOD_PHONE) {
            if ($this->replacePhone) {
                $stored = $this->storedPhone ?? '';
                $this->replacePhone = false;
                $this->storedPhone = null;
            } else {
                $stored = PhoneFormat::normalize($trimmed);
            }
            if ($stored === '') {
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
     * @param list<array{method: 'email'|'phone', label: string, value: string, is_primary: bool}> $out
     * @param array{phone: bool, email: bool} $primarySeen
     * @return list<array{method: 'email'|'phone', label: string, value: string, is_primary: bool}>
     */
    private function ensurePrimary(array $out, array $primarySeen): array
    {
        foreach (['phone', 'email'] as $method) {
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
}
