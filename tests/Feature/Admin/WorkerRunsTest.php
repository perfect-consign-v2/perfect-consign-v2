<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Admin;

use PerfectConsign\Shared\Jobs\WorkerRuns;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkerRunsTest extends TestCase
{
    private bool $passwordSaved = false;

    private string|false $savedPassword = false;

    protected function tearDown(): void
    {
        if (!$this->passwordSaved) {
            return;
        }
        if ($this->savedPassword === false || $this->savedPassword === '') {
            putenv('STALL_WORKER_PASSWORD');
        } else {
            putenv('STALL_WORKER_PASSWORD=' . $this->savedPassword);
        }
    }

    public function testAPassRecordsTheHeartbeatTheJobsAndThePrune(): void
    {
        $sql = [];
        $params = [];
        $runs = new WorkerRuns($this->prepared($sql, $params));

        $runs->completePass(' mail ', 1, 2);

        self::assertCount(3, $sql);
        self::assertStringContainsString('worker_heartbeat', $sql[0]);
        self::assertSame(['mail', 1, 2], $params[0]);
        self::assertStringContainsString('worker_runs', $sql[1]);
        self::assertStringContainsString('INSERT', $sql[1]);
        self::assertSame(['mail', 1, 2], $params[1]);
        self::assertStringContainsString('make_interval', $sql[2]);
        self::assertSame([WorkerRuns::AUTO_PRUNE_DAYS], $params[2]);
    }

    public function testAnIdlePassDoesNotWriteARunRow(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');

        (new WorkerRuns($pdo))->record('mail', 0, 0);
    }

    public function testBadWorkerCountsAndNamesAreRefused(): void
    {
        $runs = new WorkerRuns($this->createStub(PDO::class));
        $cases = [
            static fn () => $runs->heartbeat('', 0, 0),
            static fn () => $runs->heartbeat(str_repeat('w', 65), 0, 0),
            static fn () => $runs->heartbeat('mail', -1, 0),
            static fn () => $runs->record('mail', 0, -1),
            static fn () => $runs->pruneOlderThanDays(0),
        ];
        foreach ($cases as $case) {
            try {
                $case();
                self::fail('a bad worker fact was accepted');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testANameOfSixtyFourCharactersIsStored(): void
    {
        $params = [];
        $sql = [];
        $name = str_repeat('w', 64);
        (new WorkerRuns($this->prepared($sql, $params)))->heartbeat($name, 0, 0);

        self::assertSame([$name, 0, 0], $params[0]);
    }

    public function testRecentClampsTheLimitAndSkipsABadRow(): void
    {
        $limits = [];
        $left = [[
            'id' => '1',
            'worker' => 'mail',
            'started_at' => 'start',
            'finished_at' => '',
            'jobs_finished' => 2,
            'jobs_failed' => '1',
        ]];
        array_unshift($left, 'skip');
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () use (&$limits, &$left): PDOStatement {
            $rows = $left;
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(static function (array $params) use (&$limits): bool {
                $limits[] = $params[0];

                return true;
            });
            $statement->method('fetch')->willReturnCallback(static function () use (&$rows): mixed {
                if ($rows === []) {
                    return false;
                }

                return array_shift($rows);
            });

            return $statement;
        });
        $runs = new WorkerRuns($pdo);

        $listed = $runs->recent(0);
        $runs->recent(1);
        $runs->recent(500);
        $runs->recent(501);

        self::assertSame([100, 1, 500, 100], $limits);
        self::assertSame('mail', $listed[0]['worker']);
        self::assertSame(2, $listed[0]['jobs_finished']);
        self::assertSame(1, $listed[0]['jobs_failed']);
        self::assertSame('', $listed[0]['finished_at']);
    }

    public function testClearReturnsTheDeletedCount(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(4);

        self::assertSame(4, (new WorkerRuns($pdo))->clear());
    }

    public function testClearRefusesAFailedDelete(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(false);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the worker run log could not be cleared');

        (new WorkerRuns($pdo))->clear();
    }

    public function testPruneReturnsTheDeletedCount(): void
    {
        $days = null;
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () use (&$days): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(static function (array $params) use (&$days): bool {
                $days = $params[0];

                return true;
            });
            $statement->method('rowCount')->willReturn(6);

            return $statement;
        });

        self::assertSame(6, (new WorkerRuns($pdo))->pruneOlderThanDays(2));
        self::assertSame(2, $days);
    }

    public function testStatusReadsTheHeartbeatTheQueuesAndThePassword(): void
    {
        $this->password('secret');
        $withBeat = (new WorkerRuns($this->statusPdo([
            'seen_at' => '2026-11-12 01:00',
            'last_jobs_finished' => 4,
            'last_jobs_failed' => '0',
        ], 3, 5)))->status();
        self::assertTrue($withBeat['password_configured']);
        self::assertSame('2026-11-12 01:00', $withBeat['last_seen_at']);
        self::assertSame(4, $withBeat['last_jobs_finished']);
        self::assertSame(0, $withBeat['last_jobs_failed']);
        self::assertSame(3, $withBeat['waiting_mail']);
        self::assertSame(5, $withBeat['overdue_jobs']);
        self::assertSame(WorkerRuns::AUTO_PRUNE_DAYS, $withBeat['auto_prune_days']);

        $this->password('');
        $missing = (new WorkerRuns($this->statusPdo(false, 0, 0)))->status();
        self::assertFalse($missing['password_configured']);
        self::assertSame('', $missing['last_seen_at']);
        self::assertSame(0, $missing['last_jobs_finished']);
        self::assertSame(0, $missing['last_jobs_failed']);
    }

    public function testStatusTreatsAMissingQueryAsNoHeartbeat(): void
    {
        $this->password('');
        $status = (new WorkerRuns($this->statusPdo(null, 1, 2)))->status();

        self::assertSame('', $status['last_seen_at']);
        self::assertSame(1, $status['waiting_mail']);
        self::assertSame(2, $status['overdue_jobs']);
    }

    public function testAFailedCountRefusesTheStatus(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn(false);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the worker status count failed');

        (new WorkerRuns($pdo))->status();
    }

    /**
     * @param list<string> $sql
     * @param list<list<mixed>> $params
     */
    private function prepared(array &$sql, array &$params): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $statement) use (&$sql, &$params): PDOStatement {
            $prepared = $this->createStub(PDOStatement::class);
            $prepared->method('execute')->willReturnCallback(static function (array $bound) use ($statement, &$sql, &$params): bool {
                $sql[] = $statement;
                $params[] = $bound;

                return true;
            });
            $prepared->method('rowCount')->willReturn(0);

            return $prepared;
        });

        return $pdo;
    }

    /**
     * @param array<string, mixed>|false|null $heartbeat false when the heartbeat query fails, null when it has no row
     */
    private function statusPdo(array|false|null $heartbeat, int $waiting, int $overdue): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturnCallback(function (string $sql) use ($heartbeat, $waiting, $overdue): PDOStatement|false {
            if (str_contains($sql, 'worker_heartbeat')) {
                if ($heartbeat === false) {
                    return false;
                }
                $statement = $this->createStub(PDOStatement::class);
                $statement->method('fetch')->willReturn($heartbeat);

                return $statement;
            }
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('fetchColumn')->willReturn(str_contains($sql, 'mail_outbox') ? $waiting : $overdue);

            return $statement;
        });

        return $pdo;
    }

    private function password(string $password): void
    {
        if (!$this->passwordSaved) {
            $current = getenv('STALL_WORKER_PASSWORD');
            $this->savedPassword = is_string($current) ? $current : false;
            $this->passwordSaved = true;
        }
        putenv('STALL_WORKER_PASSWORD=' . $password);
    }
}
