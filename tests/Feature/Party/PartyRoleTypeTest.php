<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use PerfectConsign\Feature\Party\PartyRoleType;
use PHPUnit\Framework\TestCase;

final class PartyRoleTypeTest extends TestCase
{
    public function testConsignmentOnlyShowsConsignorNotVendor(): void
    {
        self::assertSame('consignor', PartyRoleType::displayName(PartyRoleType::VENDOR, true));
        self::assertSame('vendor', PartyRoleType::displayName(PartyRoleType::VENDOR, false));
        self::assertSame('customer', PartyRoleType::displayName(PartyRoleType::CUSTOMER, true));
        self::assertSame('wait list', PartyRoleType::displayName(PartyRoleType::WAIT_LIST, false));
    }

    public function testPartyListCsvMapsSellerAndWaitList(): void
    {
        self::assertSame(
            'customer, consignor',
            PartyRoleType::displayCsv('customer, vendor', true)
        );
        self::assertSame(
            'customer, vendor, wait list',
            PartyRoleType::displayCsv('customer, vendor, wait_list', false)
        );
        self::assertSame('', PartyRoleType::displayCsv('', true));
        self::assertSame(
            'consignor',
            PartyRoleType::displayNames([PartyRoleType::VENDOR], true)
        );
    }
}
