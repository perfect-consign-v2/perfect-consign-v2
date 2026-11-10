<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use InvalidArgumentException;
use PerfectConsign\Feature\Party\PartyRoleType;
use PHPUnit\Framework\TestCase;

final class PartyRoleTypeTest extends TestCase
{
    public function testConsignmentOnlyShowsConsignorNotVendor(): void
    {
        self::assertSame(PartyRoleType::VENDOR, PartyRoleType::assertStored('vendor'));
        self::assertSame(PartyRoleType::CUSTOMER, PartyRoleType::assertStored('customer'));
        self::assertSame(PartyRoleType::WAIT_LIST, PartyRoleType::assertStored('wait_list'));
        self::assertSame('consignor', PartyRoleType::displayName(PartyRoleType::VENDOR, true));
        self::assertSame('vendor', PartyRoleType::displayName(PartyRoleType::VENDOR, false));
        self::assertSame('customer', PartyRoleType::displayName(PartyRoleType::CUSTOMER, true));
        self::assertSame('wait list', PartyRoleType::displayName(PartyRoleType::WAIT_LIST, false));
    }

    public function testStoredRolesDecodeAndLabelWithoutCsvShortcuts(): void
    {
        self::assertSame(
            [PartyRoleType::CUSTOMER, PartyRoleType::VENDOR],
            PartyRoleType::fromAgg('customer, vendor')
        );
        self::assertSame([], PartyRoleType::fromAgg(''));
        self::assertSame(
            'customer, consignor',
            PartyRoleType::displayNames(
                [PartyRoleType::CUSTOMER, PartyRoleType::VENDOR],
                true
            )
        );
        self::assertSame(
            'customer, vendor, wait list',
            PartyRoleType::displayNames(
                [PartyRoleType::CUSTOMER, PartyRoleType::VENDOR, PartyRoleType::WAIT_LIST],
                false
            )
        );
    }

    public function testUnknownOrMalformedRolesAreRefused(): void
    {
        try {
            PartyRoleType::displayName('dealer', false);
            self::fail('an unknown role was labeled');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that party role is not recognized', $error->getMessage());
        }
        try {
            PartyRoleType::fromAgg('vendor,');
            self::fail('a malformed role list was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that party role list is malformed', $error->getMessage());
        }
        try {
            PartyRoleType::fromAgg('vendor, spook');
            self::fail('an unknown aggregated role was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that party role is not recognized', $error->getMessage());
        }
    }
}
