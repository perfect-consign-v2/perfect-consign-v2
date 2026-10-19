<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Pos\TenderSplit;
use PerfectConsign\Feature\Register\RegisterStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Http\Request;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class TenderSplitTest extends TestCase
{
    public function testASaleNeedsATender(): void
    {
        try {
            $this->split()->read(new Request('POST', '/pos/pay', [], []));
            self::fail('a sale without a tender was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a sale debits each tender once', $error->getMessage());
        }
    }

    public function testALongCheckNumberIsRefused(): void
    {
        try {
            $this->split()->read(new Request('POST', '/pos/pay', [], [
                'tender_checks' => '1.00',
                'check_number' => str_repeat('9', 41),
            ]));
            self::fail('a long check number was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a check sale needs a check number', $error->getMessage());
        }
    }

    public function testAReturnNeedsAKnownTender(): void
    {
        try {
            $this->split()->credit(new Request('POST', '/pos/return', [], [
                'return_tender' => 'points',
            ]), 100);
            self::fail('an unknown return tender was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a return credits one tender', $error->getMessage());
        }
    }

    public function testAVendorDrawNeedsAVendor(): void
    {
        try {
            $this->split()->read(new Request('POST', '/pos/pay', [], [
                'tender_vendor_draw' => '1.00',
                'vendor_id' => 'vendor',
            ]));
            self::fail('a vendor draw without a vendor was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Choose a vendor.', $error->getMessage());
        }
    }

    public function testCashIsRecordedAndAZeroTenderIsSkipped(): void
    {
        $paid = $this->split()->read(new Request('POST', '/pos/pay', [], [
            'tender_cash' => '0',
            'tender_card' => '0.00',
            'tender_checks' => '1.00',
            'check_number' => str_repeat('4', 40),
        ]));

        self::assertSame(0, $paid['cash']);
        self::assertSame(100, $paid['sum']);
        self::assertSame(str_repeat('4', 40), $paid['check']);
    }

    public function testABlankCheckNumberIsRefused(): void
    {
        try {
            $this->split()->read(new Request('POST', '/pos/pay', [], [
                'tender_checks' => '1.00',
                'check_number' => '',
            ]));
            self::fail('a blank check number was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a check sale needs a check number', $error->getMessage());
        }
    }

    public function testAVendorDrawCanTakeTheWholeOpenPayable(): void
    {
        $payouts = (new ReflectionClass(PayoutStore::class))->newInstanceWithoutConstructor();
        $pdo = $this->createStub(\PDO::class);
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetchColumn')->willReturn(100);
        $pdo->method('prepare')->willReturn($statement);
        (new ReflectionProperty(PayoutStore::class, 'pdo'))->setValue($payouts, $pdo);
        $paid = $this->split($payouts)->read(new Request('POST', '/pos/pay', [], [
            'tender_vendor_draw' => '1.00',
            'vendor_id' => '00000000-0000-7000-8000-000000000001',
        ]));

        self::assertSame(100, $paid['sum']);
        self::assertSame(0, $paid['cash']);
    }

    public function testAVendorDrawCannotExceedTheOpenPayable(): void
    {
        $payouts = (new ReflectionClass(PayoutStore::class))->newInstanceWithoutConstructor();
        $pdo = $this->createStub(\PDO::class);
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetchColumn')->willReturn(50);
        $pdo->method('prepare')->willReturn($statement);
        (new ReflectionProperty(PayoutStore::class, 'pdo'))->setValue($payouts, $pdo);
        $split = $this->split($payouts);
        $vendor = '00000000-0000-7000-8000-000000000001';
        try {
            $split->read(new Request('POST', '/pos/pay', [], [
                'tender_vendor_draw' => '1.00',
                'vendor_id' => $vendor,
            ]));
            self::fail('a vendor draw over the payable was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a vendor draw is limited to the open payable', $error->getMessage());
        }
    }

    private function split(?PayoutStore $payouts = null): TenderSplit
    {
        $split = (new ReflectionClass(TenderSplit::class))->newInstanceWithoutConstructor();
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn('00000000-0000-7000-8000-0000000000ac');
        (new ReflectionProperty(TenderSplit::class, 'ledger'))->setValue($split, $ledger);
        (new ReflectionProperty(TenderSplit::class, 'registers'))->setValue(
            $split,
            (new ReflectionClass(RegisterStore::class))->newInstanceWithoutConstructor(),
        );
        (new ReflectionProperty(TenderSplit::class, 'payouts'))->setValue(
            $split,
            $payouts ?? (new ReflectionClass(PayoutStore::class))->newInstanceWithoutConstructor(),
        );

        return $split;
    }
}
