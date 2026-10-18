<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Pos\TicketTenders;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class TicketTendersTest extends TestCase
{
    public function testAnUnknownRoleCannotBeRemoved(): void
    {
        try {
            $this->tenders($this->createStub(Statements::class))->remove('ticket', 'points');
            self::fail('an unknown tender was removed');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a sale debits each tender once', $error->getMessage());
        }
    }

    public function testAKnownRoleIsDeleted(): void
    {
        $deleted = false;
        $statements = $this->createStub(Statements::class);
        $statements->method('change')->willReturnCallback(static function () use (&$deleted): void {
            $deleted = true;
        });
        $this->tenders($statements)->remove('ticket', 'cash');
        self::assertTrue($deleted);
    }

    public function testAnEmptyTicketCannotTakeATender(): void
    {
        try {
            $this->tenders($this->createStub(Statements::class))->apply('', 'cash', 100, '', 100);
            self::fail('a tender was applied without a ticket');
        } catch (DatabaseRefusal $error) {
            self::assertSame('there is no open ticket', $error->getMessage());
        }
    }

    public function testASecondCashTenderIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([
            ['role' => 'cash', 'amount_minor' => 100, 'reference' => ''],
        ]);
        try {
            $this->tenders($statements)->apply('ticket', 'cash', 50, '', 200);
            self::fail('a second cash tender was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a sale debits each tender once', $error->getMessage());
        }
    }

    public function testTendersCannotExceedTheAmountDue(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([
            ['role' => 'card', 'amount_minor' => 100, 'reference' => ''],
        ]);
        try {
            $this->tenders($statements)->apply('ticket', 'cash', 100, '', 100);
            self::fail('an over-tender was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a sale debits the sold prices plus tax', $error->getMessage());
        }
    }

    public function testACheckNeedsANumber(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([]);
        try {
            $this->tenders($statements)->apply('ticket', 'checks', 100, '  ', 100);
            self::fail('a check without a number was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a check sale needs a check number', $error->getMessage());
        }
    }

    public function testAVendorDrawNeedsAVendor(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([]);
        try {
            $this->tenders($statements)->apply('ticket', 'vendor_draw', 100, 'vendor', 100);
            self::fail('a vendor draw without a vendor was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Choose a vendor.', $error->getMessage());
        }
    }

    public function testNonCashTendersMustCoverTheDue(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([
            ['role' => 'card', 'amount_minor' => 50, 'reference' => ''],
        ]);
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn('00000000-0000-7000-8000-0000000000ac');
        try {
            $this->tenders($statements, $ledger)->split('ticket', 100);
            self::fail('a short card tender was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the tenders do not cover the amount due', $error->getMessage());
        }
    }

    public function testCashMustMatchTheRemainder(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([
            ['role' => 'cash', 'amount_minor' => 40, 'reference' => ''],
            ['role' => 'card', 'amount_minor' => 50, 'reference' => ''],
        ]);
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn('00000000-0000-7000-8000-0000000000ac');
        try {
            $this->tenders($statements, $ledger)->split('ticket', 100);
            self::fail('a cash remainder mismatch was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the tenders do not cover the amount due', $error->getMessage());
        }
    }

    private function tenders(Statements $statements, ?LedgerStore $ledger = null): TicketTenders
    {
        $held = (new ReflectionClass(TicketTenders::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(TicketTenders::class, 'statements'))->setValue($held, $statements);
        (new ReflectionProperty(TicketTenders::class, 'ledger'))->setValue(
            $held,
            $ledger ?? $this->createStub(LedgerStore::class),
        );

        return $held;
    }
}
