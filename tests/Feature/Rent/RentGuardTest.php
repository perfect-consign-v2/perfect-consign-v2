<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use InvalidArgumentException;
use PDO;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Rent\RentStore;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\PostingFunctions;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RentGuardTest extends TestCase
{
    public function testARentDayAndMonthMustBeDates(): void
    {
        $store = $this->store();
        try {
            $store->unchargedMonths('March', '2026-03');
            self::fail('a rent day that is not a date was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a rent day is YYYY-MM-DD', $error->getMessage());
        }
        try {
            $store->unchargedMonths('2026-03-01', 'March');
            self::fail('a rent month that is not a month was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a rent month is YYYY-MM', $error->getMessage());
        }
        try {
            $store->book('March');
            self::fail('a rent book month that is not a month was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a rent month is YYYY-MM', $error->getMessage());
        }
    }

    public function testAChargeNeedsAStayAndAPeriod(): void
    {
        $store = $this->store();
        try {
            $store->chargeStay('booth', '2026-03-01');
            self::fail('a charge without a stay was accepted');
        } catch (\PerfectConsign\Ledger\DatabaseRefusal $error) {
            self::assertSame('Choose a booth to charge.', $error->getMessage());
        }
        try {
            $store->chargeStay('01900000-0000-7000-8000-000000000301', 'March');
            self::fail('a charge without a period was accepted');
        } catch (\PerfectConsign\Ledger\DatabaseRefusal $error) {
            self::assertSame('Choose the month to charge.', $error->getMessage());
        }
    }

    public function testPayoutSettingsRequireAMallRow(): void
    {
        $sqlite = new \PDO('sqlite::memory:');
        $empty = $sqlite->query('SELECT 1 AS id WHERE 0');
        self::assertInstanceOf(\PDOStatement::class, $empty);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn($empty);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the mall has no payout settings');
        (new MallPayoutSettings(new Statements($pdo)))->getCycleDay();
    }

    private function store(): RentStore
    {
        $pdo = $this->createStub(PDO::class);
        $statements = new Statements($pdo);

        return new RentStore(
            $statements,
            new MallSettings(
                $statements,
                new CompanyStore($statements, new DatabaseRole($pdo), $pdo, new CompanyLines()),
                new \PerfectConsign\Feature\Mall\MallDay($statements),
            ),
            new PostingFunctions($pdo, new LedgerPeriods($pdo), new JournalCash($pdo)),
        );
    }
}
