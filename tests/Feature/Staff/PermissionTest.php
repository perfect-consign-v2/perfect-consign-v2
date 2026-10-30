<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Staff;

use PerfectConsign\Bootstrap\Permission;
use PHPUnit\Framework\TestCase;

final class PermissionTest extends TestCase
{
    public function testTheOwnerCanWaiveAndACashierCanPost(): void
    {
        self::assertTrue(Permission::allows('owner', 'rent.waive'));
        self::assertTrue(Permission::allows('cashier', 'sale.post'));
        self::assertTrue(Permission::allows('cashier', 'sale.void'));
        self::assertFalse(Permission::allows('cashier', 'rent.waive'));
        self::assertFalse(Permission::allows('cashier', 'settings.edit'));
        self::assertTrue(Permission::allows('books', 'report.read'));
        self::assertFalse(Permission::allows('books', 'sale.post'));
        self::assertFalse(Permission::allows('cashier', 'report.read'));
        self::assertFalse(Permission::allows('vendor', 'sale.post'));
        self::assertTrue(Permission::allows('manager', 'sale.post'));
        self::assertTrue(Permission::allows('manager', 'report.read'));
        self::assertFalse(Permission::allows('manager', 'settings.edit'));
        self::assertFalse(Permission::allows('manager', 'payout.disburse'));
        self::assertContains('sale.discount', Permission::VERBS);
    }
}
