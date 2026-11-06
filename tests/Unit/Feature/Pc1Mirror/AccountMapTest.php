<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Unit\Feature\Pc1Mirror;

use PerfectConsign\Feature\Pc1Mirror\AccountMap;
use PHPUnit\Framework\TestCase;

final class AccountMapTest extends TestCase
{
    public function testRolesCoverOpeningAccounts(): void
    {
        $roles = AccountMap::roles();
        self::assertSame(AccountMap::VENDOR_PAYABLE, $roles['vendor_payable']);
        self::assertSame(AccountMap::BOOTH_RENT, $roles['booth_rent']);
        self::assertSame(AccountMap::RETAINED, $roles['retained_earnings']);
    }
}
