<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Pos\SaleJournal;
use PerfectConsign\Ledger\LedgerStore;
use PHPUnit\Framework\TestCase;

final class SaleJournalTest extends TestCase
{
    public function testAPromoAndVendorCreditsArePosted(): void
    {
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturnCallback(static fn (string $role): string => 'acct-' . $role);
        $lines = SaleJournal::sale(
            $ledger,
            [[
                'vendor_id' => 'v1',
                'price' => 1000,
                'quantity' => 1,
                'commission' => 100,
                'tax' => 80,
                'discount' => 0,
                'store_discount' => 50,
            ]],
            [['account_id' => 'acct-card', 'debit' => 930, 'credit' => 0, 'subledger_type' => null, 'subledger_ref' => null]],
            0,
            1,
            930,
        );

        self::assertSame('acct-promotion_expense', $lines[1]['account_id']);
        self::assertSame(50, $lines[1]['debit']);
        self::assertSame('acct-commission_revenue', $lines[2]['account_id']);
        self::assertSame(100, $lines[2]['credit']);
        self::assertSame('acct-sales_tax_payable', $lines[3]['account_id']);
        self::assertSame(80, $lines[3]['credit']);
        self::assertSame('acct-vendor_payable', $lines[4]['account_id']);
        self::assertSame(900, $lines[4]['credit']);
    }

    public function testExactCashUsesTheRoundedCashLine(): void
    {
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturnCallback(static fn (string $role): string => 'acct-' . $role);
        $up = SaleJournal::sale(
            $ledger,
            [],
            [['account_id' => 'acct-cash', 'debit' => 103, 'credit' => 0, 'subledger_type' => null, 'subledger_ref' => null]],
            103,
            1,
            103,
        );
        self::assertSame(105, $up[0]['debit']);
        self::assertSame('acct-cash_rounding', $up[1]['account_id']);
        self::assertSame(2, $up[1]['credit']);

        $down = SaleJournal::sale(
            $ledger,
            [],
            [['account_id' => 'acct-cash', 'debit' => 101, 'credit' => 0, 'subledger_type' => null, 'subledger_ref' => null]],
            101,
            1,
            101,
        );
        self::assertSame(100, $down[0]['debit']);
        self::assertSame(1, $down[1]['debit']);
    }

    public function testSplitCashKeepsTheTenderedAmount(): void
    {
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturnCallback(static fn (string $role): string => 'acct-' . $role);
        $up = SaleJournal::sale(
            $ledger,
            [],
            [['account_id' => 'acct-cash', 'debit' => 50, 'credit' => 0, 'subledger_type' => null, 'subledger_ref' => null]],
            50,
            1,
            103,
        );
        self::assertSame(50, $up[0]['debit']);
        self::assertSame(2, $up[1]['credit']);

        $down = SaleJournal::sale(
            $ledger,
            [],
            [['account_id' => 'acct-cash', 'debit' => 50, 'credit' => 0, 'subledger_type' => null, 'subledger_ref' => null]],
            50,
            1,
            101,
        );
        self::assertSame(1, $down[1]['debit']);
    }

    public function testAReturnGivesBackCommissionTaxPromoAndPayable(): void
    {
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturnCallback(static fn (string $role): string => 'acct-' . $role);
        $credit = ['account_id' => 'acct-cash', 'debit' => 0, 'credit' => 800, 'subledger_type' => null, 'subledger_ref' => null];
        $lines = SaleJournal::giveBack($ledger, 1000, 100, 80, 50, 25, 'v1', $credit);

        self::assertSame('acct-commission_revenue', $lines[0]['account_id']);
        self::assertSame(100, $lines[0]['debit']);
        self::assertSame('acct-sales_tax_payable', $lines[1]['account_id']);
        self::assertSame(80, $lines[1]['debit']);
        self::assertSame('acct-promotion_expense', $lines[2]['account_id']);
        self::assertSame(25, $lines[2]['credit']);
        self::assertSame('acct-vendor_payable', $lines[3]['account_id']);
        self::assertSame(850, $lines[3]['debit']);
        self::assertSame($credit, $lines[4]);
    }
}
