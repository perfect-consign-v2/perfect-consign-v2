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

    public function testTheThirdRetryableInsertStillFails(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $opens = 0;
        $status = '';
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$opens, &$status): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            if (str_contains($sql, 'INSERT INTO accounting_periods')) {
                $statement->method('execute')->willReturnCallback(static function (): bool {
                    $error = new PDOException('unique');
                    (new ReflectionProperty(PDOException::class, 'code'))->setValue($error, '23505');
                    throw $error;
                });

                return $statement;
            }
            if (str_contains($sql, 'status = ?')) {
                $statement->method('execute')->willReturnCallback(static function (array $params) use (&$status): bool {
                    $status = $params[1];

                    return true;
                });
                $statement->method('fetchColumn')->willReturnCallback(static function () use (&$opens, &$status): string {
                    if ($status !== 'open') {
                        return '';
                    }
                    $opens++;

                    return $opens >= 5 ? 'late-open' : '';
                });

                return $statement;
            }
            $statement->method('fetchColumn')->willReturn('');

            return $statement;
        });

        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('That day could not be opened.');
        (new LedgerPeriods($pdo))->idFor('book-1', '2026-06-15');
    }

    public function testADayOnTheYearBoundaryOpens(): void
    {
        self::assertSame('opened', $this->openOn('2026-01-01', '', ''));
        self::assertSame('opened', $this->openOn('2026-12-31', '', ''));
    }

    public function testAStartAfterThePostingDayIsRefused(): void
    {
        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('That day could not be opened.');
        $this->openOn('2026-06-15', '2026-06-15', '');
    }

    public function testAnExclusionCollisionRetries(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $inserts = 0;
        $opens = 0;
        $status = '';
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$inserts, &$opens, &$status): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            if (str_contains($sql, 'INSERT INTO accounting_periods')) {
                $statement->method('execute')->willReturnCallback(static function () use (&$inserts): bool {
                    $inserts++;
                    if ($inserts === 1) {
                        $error = new PDOException('overlap');
                        (new ReflectionProperty(PDOException::class, 'code'))->setValue($error, '23P01');
                        throw $error;
                    }

                    return true;
                });

                return $statement;
            }
            if (str_contains($sql, 'status = ?')) {
                $statement->method('execute')->willReturnCallback(static function (array $params) use (&$status): bool {
                    $status = $params[1];

                    return true;
                });
                $statement->method('fetchColumn')->willReturnCallback(static function () use (&$opens, &$status): string {
                    if ($status !== 'open') {
                        return '';
                    }
                    $opens++;

                    return $opens >= 4 ? 'period-retry' : '';
                });

                return $statement;
            }
            $statement->method('fetchColumn')->willReturn(false);

            return $statement;
        });

        self::assertSame('period-retry', (new LedgerPeriods($pdo))->idFor('book-1', '2026-06-15'));
        self::assertSame(2, $inserts);
    }

    public function testAnAbortedTransactionIsNotRolledBackAgain(): void
    {
        $pdo = $this->createStub(PDO::class);
        $live = true;
        $exec = [];
        $pdo->method('inTransaction')->willReturnCallback(static function () use (&$live): bool {
            return $live;
        });
        $pdo->method('exec')->willReturnCallback(static function (string $sql) use (&$exec): int {
            $exec[] = $sql;

            return 0;
        });
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$live): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            if (str_contains($sql, 'INSERT INTO accounting_periods')) {
                $statement->method('execute')->willReturnCallback(static function () use (&$live): bool {
                    $live = false;
                    $error = new PDOException('bad');
                    (new ReflectionProperty(PDOException::class, 'code'))->setValue($error, '22000');
                    throw $error;
                });

                return $statement;
            }
            $statement->method('fetchColumn')->willReturn('');

            return $statement;
        });

        try {
            (new LedgerPeriods($pdo))->idFor('book-1', '2026-06-15');
            self::fail('a bad insert opened a year');
        } catch (DatabaseRefusal) {
            self::assertSame(['SAVEPOINT stall_period'], $exec);
        }
    }

    private function openOn(string $postingDate, string $previousEnd, string $nextStart): string
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $covers = 0;
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$covers, $previousEnd, $nextStart): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            if (str_contains($sql, 'INSERT INTO accounting_periods')) {
                $statement->method('execute')->willReturn(true);

                return $statement;
            }
            if (str_contains($sql, 'ends_on')) {
                $statement->method('fetchColumn')->willReturn($previousEnd);

                return $statement;
            }
            if (str_contains($sql, 'starts_on')) {
                $statement->method('fetchColumn')->willReturn($nextStart);

                return $statement;
            }
            $statement->method('fetchColumn')->willReturnCallback(static function () use (&$covers): string {
                $covers++;

                return $covers >= 4 ? 'opened' : '';
            });

            return $statement;
        });

        return (new LedgerPeriods($pdo))->idFor('book-1', $postingDate);
    }
}
