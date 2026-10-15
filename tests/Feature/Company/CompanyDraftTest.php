<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Company;

use PerfectConsign\Feature\Company\CompanyDraft;
use PerfectConsign\Feature\Company\CompanyLines;
use PHPUnit\Framework\TestCase;

final class CompanyDraftTest extends TestCase
{
    public function testABlankNameAndABadEmailAreRefused(): void
    {
        $fields = $this->fields();
        $fields['name'] = '   ';
        try {
            CompanyDraft::parse($fields);
            self::fail('a blank store name was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Enter a store name.', $error->getMessage());
        }
        $fields['name'] = str_repeat('a', 121);
        try {
            CompanyDraft::parse($fields);
            self::fail('a long store name was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Enter a store name of 120 characters or less.', $error->getMessage());
        }
        $fields = $this->fields();
        $fields['phone'] = str_repeat('5', 41);
        try {
            CompanyDraft::parse($fields);
            self::fail('a long phone was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('that phone is too long', $error->getMessage());
        }
        $fields = $this->fields();
        $fields['email'] = 'not-an-email';
        try {
            CompanyDraft::parse($fields);
            self::fail('a bad email was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('that email is not an email address', $error->getMessage());
        }
    }

    public function testBlankContactIsOmittedFromTheReceipt(): void
    {
        $draft = CompanyDraft::parse($this->fields());
        self::assertSame('Probe Mercantile', $draft->name());
        self::assertSame(
            ['123 Main Street', 'Suite 4', 'Springfield, OR 97477', '541-555-0100', 'owner@store.test'],
            (new CompanyLines())->printed($this->shown($draft))
        );
        $empty = CompanyDraft::parse([
            'name' => ' Probe Mercantile ',
            'street' => ' ',
            'street2' => '',
            'city' => '',
            'state' => '',
            'postal' => '',
            'phone' => '',
            'email' => '',
        ]);
        self::assertSame([null, null, null, null, null, null, null], $empty->contact());
        self::assertSame([], (new CompanyLines())->printed($this->shown($empty)));
    }

    /**
     * @return array{name: string, street: string, street2: string, city: string, state: string, postal: string, phone: string, email: string}
     */
    private function fields(): array
    {
        return [
            'name' => ' Probe Mercantile ',
            'street' => ' 123 Main Street ',
            'street2' => 'Suite 4',
            'city' => 'Springfield',
            'state' => 'OR',
            'postal' => '97477',
            'phone' => '541-555-0100',
            'email' => 'owner@store.test',
        ];
    }

    /**
     * @return array{street: string, street2: string, city: string, state: string, postal: string, phone: string, email: string}
     */
    private function shown(CompanyDraft $draft): array
    {
        $contact = $draft->contact();

        return [
            'street' => $contact[0] ?? '',
            'street2' => $contact[1] ?? '',
            'city' => $contact[2] ?? '',
            'state' => $contact[3] ?? '',
            'postal' => $contact[4] ?? '',
            'phone' => $contact[5] ?? '',
            'email' => $contact[6] ?? '',
        ];
    }
}
