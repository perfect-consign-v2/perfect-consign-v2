<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use PDO;
use PerfectConsign\Feature\Rent\LateFee;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class LateFeeTest extends TestCase
{
    public function testALateFeeNeedsABoothAndADate(): void
    {
        $pdo = $this->createStub(PDO::class);
        $fee = new LateFee(new LedgerStore($pdo, new Statements($pdo), new LedgerPeriods($pdo), new JournalCash($pdo)));

        try {
            $fee->charge('booth', '2026-10-01', '1.50');
            self::fail('a late fee without a booth was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Choose a booth.', $error->getMessage());
        }

        try {
            $fee->charge('00000000-0000-7000-8000-000000000004', 'October', '1.50');
            self::fail('a late fee without a date was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Enter the date of the late fee.', $error->getMessage());
        }

        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('Choose a late fee.');
        $fee->waive('not-a-fee', '2026-10-01');
    }
}
