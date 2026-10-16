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
        $statements = new Statements($pdo);
        $fee = new LateFee(new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo)), $statements);

        try {
            $fee->post('booth', '2026-10', '2026-10-01', 150);
            self::fail('a late fee without a booth was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Choose a booth.', $error->getMessage());
        }

        try {
            $fee->post('00000000-0000-7000-8000-000000000004', '2026-10', 'October', 150);
            self::fail('a late fee without a date was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Enter the date of the late fee.', $error->getMessage());
        }

        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('Choose a late fee.');
        $fee->waive('not-a-fee', '2026-10-01');
    }
}
