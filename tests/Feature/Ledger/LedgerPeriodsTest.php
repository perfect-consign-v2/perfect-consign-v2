<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Ledger;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerPeriods;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class LedgerPeriodsTest extends TestCase
{
    public function testAYearOpensBetweenNeighboringPeriods(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $covers = 0;
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$covers): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            if (str_contains($sql, 'INSERT INTO accounting_periods')) {
                $statement->method('execute')->willReturn(true);

                return $statement;
            }
            if (str_contains($sql, 'ends_on')) {
                $statement->method('fetchColumn')->willReturn('2025-12-31');

                return $statement;
            }
            if (str_contains($sql, 'starts_on')) {
                $statement->method('fetchColumn')->willReturn('2027-01-01');

                return $statement;
            }
            $statement->method('fetchColumn')->willReturnCallback(static function () use (&$covers): string {
                $covers++;

                return $covers >= 4 ? 'period-new' : '';
            });

            return $statement;
        });

        self::assertSame('period-new', (new LedgerPeriods($pdo))->idFor('book-1', '2026-06-15'));
    }

    public function testAGapThatCannotCoverTheDayIsRefused(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            if (str_contains($sql, 'starts_on')) {
                $statement->method('fetchColumn')->willReturn('2026-02-01');

                return $statement;
            }
            $statement->method('fetchColumn')->willReturn('');

            return $statement;
        });
        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('That day could not be opened.');
        (new LedgerPeriods($pdo))->idFor('book-1', '2026-06-15');
    }

    public function testAUniqueCollisionRetriesThenGivesUp(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('exec')->willReturn(1);
        $attempts = 0;
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$attempts): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            if (str_contains($sql, 'INSERT INTO accounting_periods')) {
                $statement->method('execute')->willReturnCallback(static function () use (&$attempts): bool {
                    $attempts++;
                    $error = new PDOException('unique');
                    (new ReflectionProperty(PDOException::class, 'code'))->setValue($error, '23505');
                    throw $error;
                });

                return $statement;
            }
            $statement->method('fetchColumn')->willReturn('');

            return $statement;
        });
        try {
            (new LedgerPeriods($pdo))->idFor('book-1', '2026-06-15');
            self::fail('a colliding insert opened a year');
        } catch (DatabaseRefusal $error) {
            self::assertSame('That day could not be opened.', $error->getMessage());
            self::assertSame(3, $attempts);
        }
    }

    public function testAnOpenPeriodAppearingOnRetryIsUsed(): void
    {
        $pdo = $this->createStub(PDO::class);
        $covers = 0;
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$covers): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            if (str_contains($sql, 'status = ?')) {
                $statement->method('fetchColumn')->willReturnCallback(static function () use (&$covers): string {
                    $covers++;

                    return $covers >= 3 ? 'period-race' : '';
                });

                return $statement;
            }
            $statement->method('fetchColumn')->willReturn('');

            return $statement;
        });

        self::assertSame('period-race', (new LedgerPeriods($pdo))->idFor('book-1', '2026-06-15'));
    }

    public function testABlankNearestDateIsIgnored(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $covers = 0;
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$covers): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            if (str_contains($sql, 'INSERT INTO accounting_periods')) {
                $statement->method('execute')->willReturn(true);

                return $statement;
            }
            if (str_contains($sql, 'ends_on') || str_contains($sql, 'starts_on')) {
                $statement->method('fetchColumn')->willReturn('');

                return $statement;
            }
            $statement->method('fetchColumn')->willReturnCallback(static function () use (&$covers): string {
                $covers++;

                return $covers >= 4 ? 'period-year' : '';
            });

            return $statement;
        });

        self::assertSame('period-year', (new LedgerPeriods($pdo))->idFor('book-1', '2026-06-15'));
    }
}
