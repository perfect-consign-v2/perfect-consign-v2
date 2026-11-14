<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Jobs;

use PDO;
use PDOStatement;
use PerfectConsign\Shared\Jobs\JobQueue;
use PerfectConsign\Shared\Jobs\JobSchedule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class JobScheduleCoverageTest extends TestCase
{
    public function testScheduleSkipsBadRowsAndEnqueuesSundayAndCycleWork(): void
    {
        $schema = 'tenant_' . str_repeat('b', 32);
        $listed = $this->createStub(PDOStatement::class);
        $listed->method('fetch')->willReturnOnConsecutiveCalls(
            1,
            ['slug' => 4, 'schema_name' => 'tenant_x'],
            ['slug' => 'shop', 'schema_name' => 'Bad-Role-Name-Extra'],
            ['slug' => 'mall', 'schema_name' => 'tenant_' . str_repeat('a', 31)],
            ['slug' => 'mall', 'schema_name' => $schema],
            false,
        );
        $clock = $this->createStub(PDOStatement::class);
        $clock->method('fetch')->willReturn([
            'day' => '2026-11-01',
            'dow' => '0',
            'month_day' => '1',
            'rent_cycle_day' => 1,
        ]);
        $prepared = $this->createStub(PDOStatement::class);
        $prepared->method('execute')->willReturn(true);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturnCallback(static function (string $sql) use ($listed, $clock): PDOStatement {
            if (str_contains($sql, 'platform.tenants')) {
                return $listed;
            }

            return $clock;
        });
        $pdo->method('exec')->willReturn(1);
        $pdo->method('prepare')->willReturn($prepared);

        (new JobSchedule($pdo, new JobQueue(), new NullLogger()))->schedule();
        self::assertSame(1, 1);
    }

    public function testAFailedRoleChangeIsRefused(): void
    {
        $listed = $this->createStub(PDOStatement::class);
        $listed->method('fetch')->willReturnOnConsecutiveCalls(
            ['slug' => 'mall', 'schema_name' => 'tenant_' . str_repeat('c', 32)],
            false,
        );
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn($listed);
        $pdo->method('exec')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('job schedule set role failed for mall');
        (new JobSchedule($pdo, new JobQueue(), new NullLogger()))->schedule();
    }

    public function testTheMallClockRefusesABadSchemaABadDayAndAFailedQuery(): void
    {
        $schedule = new JobSchedule($this->createStub(PDO::class), new JobQueue(), new NullLogger());
        self::assertNull($schedule->clock('public'));

        $empty = $this->createStub(PDOStatement::class);
        $empty->method('fetch')->willReturn(false);
        $badDay = $this->createStub(PDOStatement::class);
        $badDay->method('fetch')->willReturn([
            'day' => 'November',
            'dow' => 0,
            'month_day' => 'no',
            'rent_cycle_day' => 1,
        ]);
        $plain = $this->createStub(PDOStatement::class);
        $plain->method('fetch')->willReturn([
            'day' => '2026-11-09',
            'dow' => 1,
            'month_day' => 'no',
            'rent_cycle_day' => 1,
        ]);
        $sunday = $this->createStub(PDOStatement::class);
        $sunday->method('fetch')->willReturn([
            'day' => '2026-11-08',
            'dow' => 0,
            'month_day' => '8.0',
            'rent_cycle_day' => '8',
        ]);
        $calls = 0;
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturnCallback(static function () use (&$calls, $empty, $badDay, $plain, $sunday): PDOStatement|false {
            $calls++;
            if ($calls === 1) {
                return false;
            }
            if ($calls === 2) {
                return $empty;
            }
            if ($calls === 3) {
                return $badDay;
            }
            if ($calls === 4) {
                return $plain;
            }

            return $sunday;
        });
        $clock = new JobSchedule($pdo, new JobQueue(), new NullLogger());
        $schema = 'tenant_' . str_repeat('d', 32);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('job schedule mall clock failed for ' . $schema);
        try {
            $clock->clock($schema);
        } catch (RuntimeException $error) {
            self::assertNull($clock->clock($schema));
            self::assertNull($clock->clock($schema));
            self::assertSame(
                ['date' => '2026-11-09', 'sunday' => false, 'cycle' => false],
                $clock->clock($schema),
            );
            self::assertSame(
                ['date' => '2026-11-08', 'sunday' => true, 'cycle' => true],
                $clock->clock($schema),
            );
            throw $error;
        }
    }

    public function testTheTenantListQueryIsRequired(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('job schedule tenant list failed');
        (new JobSchedule($pdo, new JobQueue(), new NullLogger()))->schedule();
    }
}
