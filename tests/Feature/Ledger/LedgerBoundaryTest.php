<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Ledger;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class LedgerBoundaryTest extends TestCase
{
    public function testACentIsStoredAndZeroIsNot(): void
    {
        $calls = 0;
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturnCallback(static function () use (&$calls): bool {
            $calls++;

            return true;
        });
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $cash = new JournalCash($pdo);
        $id = '01900000-0000-7000-8000-0000000000d1';

        $cash->storeTendered($id, null);
        $cash->storeTendered($id, 0);
        self::assertSame(0, $calls);
        $cash->storeTendered($id, 1);
        self::assertSame(1, $calls);
    }

    public function testAnEmptyRoleAccountIsRefused(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn('');
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('the chart has no cash account');
        $this->ledger($pdo)->roleAccount('cash');
    }

    public function testAnEmptyBookIdIsRefused(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetch')->willReturn(['id' => '', 'currency' => 'USD']);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn($statement);
        $book = new ReflectionMethod(LedgerStore::class, 'defaultBook');

        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('the mall book is missing');
        $book->invoke($this->ledger($pdo));
    }

    public function testAnOpenPeriodIsUsedAndABlankOneIsClosed(): void
    {
        $values = ['period-1', 'closed-period'];
        $open = $this->createStub(PDOStatement::class);
        $open->method('fetchColumn')->willReturnCallback(static function () use (&$values): string {
            $next = array_shift($values);

            return is_string($next) ? $next : '';
        });
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($open);

        self::assertSame('period-1', (new LedgerPeriods($pdo))->idFor('book-1', '2026-10-01'));

        $blank = $this->createStub(PDOStatement::class);
        $step = 0;
        $blank->method('fetchColumn')->willReturnCallback(static function () use (&$step): string {
            $step++;

            return $step === 1 ? '' : 'closed-period';
        });
        $closed = $this->createStub(PDO::class);
        $closed->method('prepare')->willReturn($blank);

        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('That day is already closed.');
        (new LedgerPeriods($closed))->idFor('book-1', '2026-10-01');
    }

    private function ledger(PDO $pdo): LedgerStore
    {
        return new LedgerStore($pdo, new Statements($pdo), new LedgerPeriods($pdo), new JournalCash($pdo));
    }
}
