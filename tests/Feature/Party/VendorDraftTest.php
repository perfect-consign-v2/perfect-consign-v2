<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use PerfectConsign\Feature\Party\VendorDraft;
use PHPUnit\Framework\TestCase;

final class VendorDraftTest extends TestCase
{
    public function testAnEndBeforeTheStartIsRefused(): void
    {
        try {
            $this->draft(['agreement_starts_on' => '2026-02-02', 'agreement_ends_on' => '2026-02-01'])->values();
            self::fail('an earlier end was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('an agreement end cannot be before its start', $error->getMessage());
        }
    }

    public function testOneAgreementDateDoesNotNeedTheOther(): void
    {
        $values = $this->draft(['agreement_starts_on' => '2026-02-02'])->values();

        self::assertSame('2026-02-02', $values[7]);
        self::assertNull($values[8]);
    }

    public function testTheSameAgreementDayIsKept(): void
    {
        $values = $this->draft([
            'agreement_starts_on' => '2026-02-02',
            'agreement_ends_on' => '2026-02-02',
        ])->values();

        self::assertSame('2026-02-02', $values[8]);
    }

    public function testTaxExemptNeedsANumberAndLongTextIsRefused(): void
    {
        try {
            $this->draft(['tax_exempt' => '1'])->values();
            self::fail('a blank resale number was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Tax exempt number is required when tax exempt is enabled', $error->getMessage());
        }
        try {
            $this->draft(['tax_exempt_number' => str_repeat('n', 101)])->values();
            self::fail('a long resale number was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('that resale number is too long', $error->getMessage());
        }
    }

    public function testContactLimitsRefuseTheNextCharacter(): void
    {
        try {
            $this->draft(['phone' => str_repeat('5', 41)])->values();
            self::fail('a long phone was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('that phone is too long', $error->getMessage());
        }
        try {
            $this->draft(['email' => str_repeat('a', 196) . '@b.co'])->values();
            self::fail('a long email was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('that email is not an email address', $error->getMessage());
        }
        $values = $this->draft(['email' => 'a@b.co'])->values();
        self::assertSame('a@b.co', $values[4]);
        try {
            $this->draft(['address' => str_repeat('a', 401)])->values();
            self::fail('a long address was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('that address is too long', $error->getMessage());
        }
    }

    /**
     * @param array<string, string> $overrides
     */
    private function draft(array $overrides): VendorDraft
    {
        $fields = [
            'code' => 'A1',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'phone' => '',
            'email' => '',
            'address' => '',
            'agreement_starts_on' => '',
            'agreement_ends_on' => '',
            'tax_id' => '',
            'notes' => '',
            'tax_exempt' => '',
            'tax_exempt_number' => '',
        ];

        return new VendorDraft($overrides + $fields, null);
    }
}
