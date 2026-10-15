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
use PerfectConsign\Feature\Rent\RentLines;
use PerfectConsign\Feature\Rent\RentStore;
use PerfectConsign\Ledger\JournalCash;
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
            $store->uncharged('March', '2026-03');
            self::fail('a rent day that is not a date was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a rent day is YYYY-MM-DD', $error->getMessage());
        }
        try {
            $store->uncharged('2026-03-01', 'March');
            self::fail('a rent month that is not a month was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a rent month is YYYY-MM', $error->getMessage());
        }
        try {
            $store->facts('March');
            self::fail('a rent book month that is not a month was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a rent month is YYYY-MM', $error->getMessage());
        }
    }

    public function testPayoutSettingsRequireAMallRow(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the mall has no payout settings');
        (new MallPayoutSettings(new Statements($pdo)))->cycleDay();
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
            ),
            new RentLines(),
            new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo)),
        );
    }
}
