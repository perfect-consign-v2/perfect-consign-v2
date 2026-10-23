<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Staff;

use PerfectConsign\Bootstrap\RoleGate;
use PHPUnit\Framework\TestCase;

final class RoleGateTest extends TestCase
{
    public function testACashierCanSellTakeRentAndCloseTheDrawer(): void
    {
        self::assertTrue(RoleGate::allows('cashier', '/pos', 'GET'));
        self::assertTrue(RoleGate::allows('cashier', '/pos/pay', 'POST'));
        self::assertTrue(RoleGate::allows('cashier', '/rent', 'GET'));
        self::assertTrue(RoleGate::allows('cashier', '/rent/collect', 'GET'));
        self::assertTrue(RoleGate::allows('cashier', '/rent/payment', 'POST'));
        self::assertTrue(RoleGate::allows('cashier', '/registers/01900000-0000-7000-8000-000000000d10/close', 'POST'));
        self::assertTrue(RoleGate::allows('cashier', '/registers/01900000-0000-7000-8000-000000000d10/open', 'POST'));
        self::assertTrue(RoleGate::allows('cashier', '/password', 'GET'));
        self::assertTrue(RoleGate::allows('cashier', '/password', 'POST'));
        self::assertTrue(RoleGate::allows('cashier', '/password/email', 'POST'));
        self::assertFalse(RoleGate::allows('cashier', '/staff', 'POST'));
    }

    public function testACashierIsRefusedFromBooksSettingsAndPayouts(): void
    {
        self::assertFalse(RoleGate::allows('cashier', '/book', 'GET'));
        self::assertFalse(RoleGate::allows('cashier', '/settings', 'GET'));
        self::assertFalse(RoleGate::allows('cashier', '/company', 'GET'));
        self::assertFalse(RoleGate::allows('cashier', '/company', 'POST'));
        self::assertFalse(RoleGate::allows('cashier', '/payouts/undo', 'POST'));
        self::assertFalse(RoleGate::allows('cashier', '/close', 'GET'));
        self::assertFalse(RoleGate::allows('cashier', '/rent/charge', 'POST'));
        self::assertFalse(RoleGate::allows('cashier', '/rent/late-fee', 'POST'));
        self::assertFalse(RoleGate::allows('cashier', '/rent/late-fees', 'POST'));
        self::assertFalse(RoleGate::allows('cashier', '/reports', 'GET'));
        self::assertFalse(RoleGate::allows('cashier', '/registers/new', 'GET'));
        self::assertFalse(RoleGate::allows('cashier', '/registers', 'POST'));
    }

    public function testAVendorSeesOnlyTheirOwnPage(): void
    {
        self::assertTrue(RoleGate::allows('vendor', '/vendor', 'GET'));
        self::assertTrue(RoleGate::allows('vendor', '/vendor/labels', 'POST'));
        self::assertTrue(RoleGate::allows('vendor', '/vendor/sales', 'GET'));
        self::assertTrue(RoleGate::allows('vendor', '/vendor/inventory', 'GET'));
        self::assertTrue(RoleGate::allows('vendor', '/vendor/offers', 'GET'));
        self::assertTrue(RoleGate::allows('vendor', '/vendor/offers/new', 'GET'));
        self::assertFalse(RoleGate::allows('cashier', '/offers', 'GET'));
        self::assertFalse(RoleGate::allows('vendor', '/offers', 'GET'));
        self::assertTrue(RoleGate::allows('vendor', '/vendor/checks/01900000-0000-7000-8000-000000000d10', 'GET'));
        self::assertTrue(RoleGate::allows('vendor', '/password', 'GET'));
        self::assertTrue(RoleGate::allows('vendor', '/password', 'POST'));
        self::assertTrue(RoleGate::allows('vendor', '/password/email', 'POST'));
        self::assertFalse(RoleGate::allows('vendor', '/company', 'GET'));
        self::assertFalse(RoleGate::allows('vendor', '/pos', 'GET'));
        self::assertFalse(RoleGate::allows('vendor', '/book', 'GET'));
        self::assertFalse(RoleGate::allows('vendor', '/vendors/01900000-0000-7000-8000-000000000d10', 'GET'));
        $photo = '/inventory/01900000-0000-7000-8000-000000000d10/photo';
        self::assertTrue(RoleGate::allows('vendor', $photo, 'GET'));
        self::assertFalse(RoleGate::allows('vendor', $photo, 'POST'));
    }

    public function testABooksLoginReadsTheBooksAndMaySignOut(): void
    {
        self::assertTrue(RoleGate::allows('books', '/sales', 'GET'));
        self::assertTrue(RoleGate::allows('books', '/month', 'GET'));
        self::assertTrue(RoleGate::allows('books', '/rent', 'GET'));
        self::assertTrue(RoleGate::allows('books', '/payouts', 'GET'));
        self::assertTrue(RoleGate::allows('books', '/payouts/past', 'GET'));
        self::assertTrue(RoleGate::allows('books', '/journals', 'GET'));
        self::assertTrue(RoleGate::allows('books', '/journals/01900000-0000-7000-8000-000000000d10', 'GET'));
        self::assertTrue(RoleGate::allows('books', '/rent/receipt/01900000-0000-7000-8000-000000000d10', 'GET'));
        self::assertTrue(RoleGate::allows('books', '/pos/receipt/01900000-0000-7000-8000-000000000d10', 'GET'));
        self::assertTrue(RoleGate::allows('books', '/dashboard', 'GET'));
        self::assertTrue(RoleGate::allows('books', '/reports', 'GET'));
        self::assertTrue(RoleGate::allows('books', '/reports/sales.csv', 'GET'));
        self::assertFalse(RoleGate::allows('books', '/reports/side-effect', 'GET'));
        self::assertFalse(RoleGate::allows('books', '/reports', 'POST'));
        self::assertTrue(RoleGate::allows('books', '/password', 'GET'));
        self::assertTrue(RoleGate::allows('books', '/logout', 'POST'));
        self::assertTrue(RoleGate::allows('books', '/logout/confirm', 'POST'));
        self::assertTrue(RoleGate::allows('books', '/password', 'POST'));
        self::assertTrue(RoleGate::allows('books', '/password/email', 'POST'));
        self::assertFalse(RoleGate::allows('books', '/pos', 'GET'));
        self::assertFalse(RoleGate::allows('books', '/staff', 'PUT'));
        self::assertFalse(RoleGate::allows('books', '/settings', 'GET'));
        self::assertFalse(RoleGate::allows('', '/sales', 'GET'));
    }
}
