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
            5,
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
            5,
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
            5,
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
            5,
            103,
        );
        self::assertSame(50, $up[0]['debit']);
        self::assertSame(2, $up[1]['credit']);

        $down = SaleJournal::sale(
            $ledger,
            [],
            [['account_id' => 'acct-cash', 'debit' => 50, 'credit' => 0, 'subledger_type' => null, 'subledger_ref' => null]],
            50,
            5,
            101,
        );
        self::assertSame(1, $down[1]['debit']);
    }

    public function testAReturnGivesBackCommissionTaxPromoAndPayable(): void
    {
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturnCallback(static fn (string $role): string => 'acct-' . $role);
        $credit = ['account_id' => 'acct-cash', 'debit' => 0, 'credit' => 800, 'subledger_type' => null, 'subledger_ref' => null];
        $lines = SaleJournal::giveBack($ledger, [
            'price' => 1000,
            'commission' => 100,
            'tax' => 80,
            'discount' => 50,
            'store_discount' => 25,
        ], 'v1', $credit, 850);

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

    public function testAReturnWithNoOpenPayableClawsBackReceivable(): void
    {
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturnCallback(static fn (string $role): string => 'acct-' . $role);
        $credit = ['account_id' => 'acct-cash', 'debit' => 0, 'credit' => 800, 'subledger_type' => null, 'subledger_ref' => null];
        $lines = SaleJournal::giveBack($ledger, [
            'price' => 1000,
            'commission' => 100,
            'tax' => 80,
            'discount' => 50,
            'store_discount' => 25,
        ], 'v1', $credit, 0);

        self::assertSame('acct-vendor_receivable', $lines[3]['account_id']);
        self::assertSame(850, $lines[3]['debit']);
        self::assertSame($credit, $lines[4]);
    }

    public function testRoundingStaysOffWhenTheMallDoesNotRound(): void
    {
        $ledger = $this->ledger();
        $lines = SaleJournal::sale(
            $ledger,
            [],
            [['account_id' => 'acct-cash', 'debit' => 103, 'credit' => 0, 'subledger_type' => null, 'subledger_ref' => null]],
            103,
            0,
            103,
        );

        self::assertSame(103, $lines[0]['debit']);
        self::assertCount(1, $lines);
    }

    public function testAZeroPromoAndAZeroOwedAddNoCreditLines(): void
    {
        $ledger = $this->ledger();
        $tender = ['account_id' => 'acct-card', 'debit' => 100, 'credit' => 0, 'subledger_type' => null, 'subledger_ref' => null];
        $lines = SaleJournal::sale(
            $ledger,
            [[
                'vendor_id' => 'v1',
                'price' => 100,
                'quantity' => 1,
                'commission' => 0,
                'tax' => 0,
                'discount' => 100,
                'store_discount' => 0,
            ]],
            [$tender],
            0,
            0,
            100,
        );

        self::assertSame([$tender], $lines);
    }

    public function testQuantityAndDiscountChangeWhatTheVendorIsOwed(): void
    {
        $ledger = $this->ledger();
        $lines = SaleJournal::sale(
            $ledger,
            [[
                'vendor_id' => 'v1',
                'price' => 1000,
                'quantity' => 2,
                'commission' => 50,
                'tax' => 0,
                'discount' => 100,
                'store_discount' => 0,
            ]],
            [['account_id' => 'acct-card', 'debit' => 1850, 'credit' => 0, 'subledger_type' => null, 'subledger_ref' => null]],
            0,
            0,
            1850,
        );

        self::assertSame(50, $lines[1]['credit']);
        self::assertSame(1850, $lines[2]['credit']);
    }

    public function testAReturnWithNothingToGiveBackPostsOnlyTheCredit(): void
    {
        $ledger = $this->ledger();
        $credit = ['account_id' => 'acct-cash', 'debit' => 0, 'credit' => 100, 'subledger_type' => null, 'subledger_ref' => null];

        self::assertSame([$credit], SaleJournal::giveBack($ledger, [
            'price' => 100,
            'commission' => 0,
            'tax' => 0,
            'discount' => 100,
            'store_discount' => 0,
        ], 'v1', $credit));
    }

    public function testZeroCashDoesNotRoundAnEmptyTender(): void
    {
        $lines = SaleJournal::sale($this->ledger(), [], [], 0, 5, 103);

        self::assertSame([], $lines);
    }

    public function testOnlyTheCashLineThatMatchesTheTenderIsRounded(): void
    {
        $lines = SaleJournal::sale(
            $this->ledger(),
            [],
            [
                ['account_id' => 'acct-cash', 'debit' => 50, 'credit' => 0, 'subledger_type' => null, 'subledger_ref' => null],
                ['account_id' => 'acct-card', 'debit' => 53, 'credit' => 0, 'subledger_type' => null, 'subledger_ref' => null],
            ],
            103,
            5,
            103,
        );

        self::assertSame(50, $lines[0]['debit']);
        self::assertSame(53, $lines[1]['debit']);
        self::assertSame(2, $lines[2]['credit']);
    }

    private function ledger(): LedgerStore
    {
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturnCallback(static fn (string $role): string => 'acct-' . $role);

        return $ledger;
    }
}
