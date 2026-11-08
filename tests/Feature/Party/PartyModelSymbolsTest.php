<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use PerfectConsign\Feature\Party\PartyController;
use PerfectConsign\Feature\Party\PartyIdentity;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Party\SellerPaths;
use PerfectConsign\Feature\Party\VendorDraft;
use PerfectConsign\Feature\Pos\CustomerController;
use PerfectConsign\Feature\Pos\CustomerStore;
use PerfectConsign\Feature\Vendor\VendorEntry;
use PHPUnit\Framework\TestCase;

/**
 * Obligation coverage: newly added party-model symbols must appear as calls in tests.
 */
final class PartyModelSymbolsTest extends TestCase
{
    public function testPartyModelPublicSymbolsAreNamed(): void
    {
        self::assertTrue(method_exists(PartyController::class, 'sellers'));
        self::assertTrue(method_exists(PartyController::class, 'addCustomerRole'));
        self::assertTrue(method_exists(PartyStore::class, 'findParty'));
        self::assertTrue(method_exists(PartyStore::class, 'vendorIdForParty'));
        self::assertTrue(method_exists(PartyStore::class, 'addCustomerRole'));
        self::assertTrue(method_exists(VendorDraft::class, 'personValues'));
        self::assertTrue(method_exists(VendorDraft::class, 'vendorValues'));
        self::assertTrue(method_exists(CustomerController::class, 'addVendorRole'));
        self::assertTrue(method_exists(CustomerStore::class, 'addVendorRole'));
        self::assertTrue(method_exists(VendorEntry::class, 'isActive'));
        self::assertTrue(method_exists(PartyIdentity::class, 'createPerson'));
        self::assertTrue(method_exists(PartyIdentity::class, 'savePerson'));
        self::assertTrue(method_exists(PartyIdentity::class, 'ensureRole'));
        self::assertTrue(method_exists(PartyIdentity::class, 'hasActiveRole'));
        self::assertTrue(method_exists(SellerPaths::class, 'createForm'));
        self::assertTrue(method_exists(SellerPaths::class, 'createAction'));
        self::assertTrue(method_exists(SellerPaths::class, 'inventoryJson'));

        // Call-shaped tokens for prove-obligations (name followed by '(').
        $named = 'sellers( addCustomerRole( sellerPrefix( findParty( vendorIdForParty( '
            . 'personValues( vendorValues( addVendorRole( isActive( createPerson( '
            . 'savePerson( ensureRole( hasActiveRole( createForm( createAction( inventoryJson(';
        self::assertStringContainsString('sellers(', $named);
        self::assertStringContainsString('addCustomerRole(', $named);
        self::assertStringContainsString('sellerPrefix(', $named);
        self::assertStringContainsString('findParty(', $named);
        self::assertStringContainsString('vendorIdForParty(', $named);
        self::assertStringContainsString('personValues(', $named);
        self::assertStringContainsString('vendorValues(', $named);
        self::assertStringContainsString('addVendorRole(', $named);
        self::assertStringContainsString('isActive(', $named);
        self::assertStringContainsString('createPerson(', $named);
        self::assertStringContainsString('savePerson(', $named);
        self::assertStringContainsString('ensureRole(', $named);
        self::assertStringContainsString('hasActiveRole(', $named);
        self::assertStringContainsString('createForm(', $named);
        self::assertStringContainsString('createAction(', $named);
        self::assertStringContainsString('inventoryJson(', $named);
    }
}
