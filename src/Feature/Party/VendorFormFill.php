<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

/** Maps stored vendor/party columns onto the edit-form array. */
final class VendorFormFill
{
    /**
     * @param array<string, mixed> $vendor
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    public function apply(array $vendor, array $stored, int $mallBasis, VendorFormScalars $scalars): array
    {
        $savedBasis = $stored['commission_bps'] ?? null;
        $vendor['store_take'] = $scalars->percent($savedBasis === null ? $mallBasis : $savedBasis);
        $vendor['store_take_overridden'] = $savedBasis !== null && $scalars->points($savedBasis) !== $mallBasis;
        $vendor['agreement_starts_on'] = $scalars->plain($stored['agreement_starts_on'] ?? null);
        $vendor['agreement_ends_on'] = $scalars->plain($stored['agreement_ends_on'] ?? null);
        $vendor['tax_id'] = $scalars->plain($stored['tax_id'] ?? null);
        $vendor['notes'] = $scalars->plain($stored['notes'] ?? null);
        $vendor['tax_exempt'] = $scalars->enabled($stored['tax_exempt'] ?? false);
        $vendor['tax_exempt_number'] = $scalars->plain($stored['tax_exempt_number'] ?? null);
        $vendor['business_name'] = $scalars->plain($stored['business_name'] ?? null);
        $vendor['discount_by'] = $scalars->plain($stored['discount_by'] ?? 'staff');
        $kept = $stored['keep_days'] ?? null;
        $vendor['keep_days'] = is_int($kept) ? (string) $kept : (is_string($kept) ? $kept : '');
        $vendor['expiry_action'] = $scalars->plain($stored['expiry_action'] ?? 'return');
        $vendor['email_bounced'] = $scalars->enabled($stored['email_bounced'] ?? false);
        $vendor = $this->person($vendor, $stored, $scalars);
        $vendor['default_store_take'] = $scalars->percent($mallBasis);

        return $vendor;
    }

    /**
     * @param array<string, mixed> $vendor
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private function person(array $vendor, array $stored, VendorFormScalars $scalars): array
    {
        $vendor['first_name'] = $scalars->plain($stored['first_name'] ?? null);
        $vendor['last_name'] = $scalars->plain($stored['last_name'] ?? null);
        $vendor['phone'] = $scalars->plain($stored['phone'] ?? null);
        $vendor['email'] = $scalars->plain($stored['email'] ?? null);
        $vendor['address'] = $scalars->plain($stored['address'] ?? null);
        $vendor['city'] = $scalars->plain($stored['city'] ?? null);
        $vendor['state'] = $scalars->plain($stored['state'] ?? null);
        $vendor['postal'] = $scalars->plain($stored['postal'] ?? null);
        $vendor['phones'] = $scalars->stringList($stored['phones'] ?? []);
        $vendor['emails'] = $scalars->stringList($stored['emails'] ?? []);
        $vendor['mailing_address'] = $scalars->plain($stored['mailing_address'] ?? null);
        $vendor['mailing_city'] = $scalars->plain($stored['mailing_city'] ?? null);
        $vendor['mailing_state'] = $scalars->plain($stored['mailing_state'] ?? null);
        $vendor['mailing_postal'] = $scalars->plain($stored['mailing_postal'] ?? null);
        $vendor['mailing_same'] = $this->mailingSame($vendor, $scalars);

        return $vendor;
    }

    /**
     * @param array<string, mixed> $vendor
     */
    private function mailingSame(array $vendor, VendorFormScalars $scalars): bool
    {
        $physical = trim($scalars->plain($vendor['address'] ?? null) . '|'
            . $scalars->plain($vendor['city'] ?? null) . '|'
            . $scalars->plain($vendor['state'] ?? null) . '|'
            . $scalars->plain($vendor['postal'] ?? null));
        $mailing = trim($scalars->plain($vendor['mailing_address'] ?? null) . '|'
            . $scalars->plain($vendor['mailing_city'] ?? null) . '|'
            . $scalars->plain($vendor['mailing_state'] ?? null) . '|'
            . $scalars->plain($vendor['mailing_postal'] ?? null));
        if ($mailing === '|||') {
            return false;
        }

        return $physical === $mailing;
    }
}
