<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Jobs;

use PDO;
use PDOStatement;
use PerfectConsign\Shared\Jobs\JobQueue;
use PerfectConsign\Shared\Jobs\JobSchedule;
use PerfectConsign\Shared\Jobs\ProbeBanDigestJob;
use PerfectConsign\Shared\Mail\MailOutbox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class ProbeBanDigestJobTest extends TestCase
{
    private string|false $adminEmail = false;

    protected function setUp(): void
    {
        $this->adminEmail = getenv('STALL_PLATFORM_ADMIN_EMAIL');
    }

    protected function tearDown(): void
    {
        if (is_string($this->adminEmail)) {
            putenv('STALL_PLATFORM_ADMIN_EMAIL=' . $this->adminEmail);

            return;
        }
        putenv('STALL_PLATFORM_ADMIN_EMAIL');
    }

    public function testTheBodyNamesEachBanInPlainWords(): void
    {
        $body = ProbeBanDigestJob::body('2026-11-25', [
            $this->row('203.0.113.1', 'streak', 1),
            $this->row('203.0.113.2', 'scanner', 3),
            $this->row('203.0.113.3', 'other', 1),
        ]);

        self::assertStringStartsWith('3 addresses were banned in the day before the 2026-11-25 summary.', $body);
        self::assertStringContainsString('Times are America/Los_Angeles. Lift a ban in Admin, App log.', $body);
        self::assertStringContainsString("203.0.113.1\nWhy: too many probe requests\nBan number: 1\nPath: /x.php\nStarted: 2026-11-24 13:02\nEnds: 2026-11-24 13:17\n", $body);
        self::assertStringContainsString("203.0.113.2\nWhy: a scanning tool\nBan number: 3\n", $body);
        self::assertStringContainsString("Why: other\n", $body);
        self::assertStringNotContainsString('more.', $body);
    }

    public function testOneBanReadsAsOneAddressAndALongListIsCut(): void
    {
        self::assertStringStartsWith(
            'One address was banned in the day before the 2026-11-25 summary.',
            ProbeBanDigestJob::body('2026-11-25', [$this->row('203.0.113.1', 'streak', 1)]),
        );
        $rows = [];
        for ($index = 1; $index <= ProbeBanDigestJob::MOST_LISTED + 2; $index++) {
            $rows[] = $this->row('203.0.113.' . (string) $index, 'streak', 1);
        }
        $body = ProbeBanDigestJob::body('2026-11-25', $rows);
        self::assertStringContainsString('203.0.113.' . (string) ProbeBanDigestJob::MOST_LISTED . "\n", $body);
        self::assertStringNotContainsString('203.0.113.' . (string) (ProbeBanDigestJob::MOST_LISTED + 1) . "\n", $body);
        self::assertStringEndsWith("And 2 more. App log lists every ban.\n", $body);
        self::assertLessThan(20000, strlen($body));
    }

    public function testABadDayIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('job date is missing');
        $this->job($this->createStub(PDO::class))->send('today');
    }

    public function testNoAdminAddressReadsNothing(): void
    {
        putenv('STALL_PLATFORM_ADMIN_EMAIL=');
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $this->job($pdo)->send('2026-11-25');
    }

    public function testADayWithNoBansFilesNothing(): void
    {
        putenv('STALL_PLATFORM_ADMIN_EMAIL=Ops@Example.test');
        $bans = $this->createStub(PDOStatement::class);
        $bans->method('fetch')->willReturn(false);
        $prepared = [];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(static function (string $sql) use ($bans, &$prepared): PDOStatement {
            $prepared[] = $sql;

            return $bans;
        });

        $this->job($pdo)->send('2026-11-25');
        self::assertCount(1, $prepared);
        self::assertStringContainsString('platform.probe_ban_summary(?::date)', $prepared[0]);
    }

    public function testTheLetterIsFiledOnceForTheDay(): void
    {
        putenv('STALL_PLATFORM_ADMIN_EMAIL=Ops@Example.test');
        $filed = [];
        foreach ([false, true] as $already) {
            $filed[] = $this->filedFor($already);
        }

        self::assertCount(1, $filed[0]);
        self::assertSame(
            ['platform', 'platform', 'probe-ban-digest', 'ops@example.test', 'Perfect Consign: new probe bans for 2026-11-25'],
            array_slice($filed[0][0], 0, 5),
        );
        self::assertStringContainsString('203.0.113.9', (string) $filed[0][0][5]);
        self::assertSame([], $filed[1]);
    }

    public function testTheScheduleFilesTheSummaryOnceItIsDue(): void
    {
        self::assertSame([['platform', 'probe-ban-digest', '2026-11-25']], $this->scheduled('2026-11-25'));
        self::assertSame([], $this->scheduled(null));
        self::assertSame([], $this->scheduled('soon'));
    }

    public function testTheScheduleRefusesAFailedSummaryQuery(): void
    {
        $tenants = $this->createStub(PDOStatement::class);
        $tenants->method('fetch')->willReturn(false);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturnCallback(static function (string $sql) use ($tenants): PDOStatement|false {
            return str_contains($sql, 'platform.tenants') ? $tenants : false;
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('job schedule ban summary day failed');
        (new JobSchedule($pdo, new JobQueue(), new NullLogger()))->schedule();
    }

    /**
     * @return list<list<mixed>>
     */
    private function scheduled(?string $day): array
    {
        $tenants = $this->createStub(PDOStatement::class);
        $tenants->method('fetch')->willReturn(false);
        $due = $this->createStub(PDOStatement::class);
        $due->method('fetchColumn')->willReturn($day ?? false);
        $enqueued = [];
        $insert = $this->createStub(PDOStatement::class);
        $insert->method('execute')->willReturnCallback(static function (array $params) use (&$enqueued): bool {
            $enqueued[] = array_values($params);

            return true;
        });
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturnCallback(static function (string $sql) use ($tenants, $due): PDOStatement {
            return str_contains($sql, 'platform.tenants') ? $tenants : $due;
        });
        $pdo->method('prepare')->willReturn($insert);
        (new JobSchedule($pdo, new JobQueue(), new NullLogger()))->schedule();

        return $enqueued;
    }

    /**
     * @return list<list<mixed>>
     */
    private function filedFor(bool $already): array
    {
        $bans = $this->createStub(PDOStatement::class);
        $bans->method('fetch')->willReturnOnConsecutiveCalls(
            [
                'address' => '203.0.113.9',
                'reason' => 'streak',
                'strikes' => '2',
                'path' => '/x.php',
                'banned_at' => '2026-11-24 13:02',
                'banned_until' => '2026-11-24 13:17',
                'time_zone' => 'America/Los_Angeles',
            ],
            'not a row',
            false,
        );
        $seen = $this->createStub(PDOStatement::class);
        $seen->method('fetchColumn')->willReturn($already ? 1 : false);
        $filed = [];
        $file = $this->createStub(PDOStatement::class);
        $file->method('execute')->willReturnCallback(static function (array $params) use (&$filed): bool {
            $filed[] = array_values($params);

            return true;
        });
        $file->method('fetchColumn')->willReturn('01900000-0000-7000-8000-00000000d001');
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            static function (string $sql) use ($bans, $seen, $file): PDOStatement {
                if (str_contains($sql, 'probe_ban_summary')) {
                    return $bans;
                }
                if (str_contains($sql, 'mail_outbox')) {
                    return $seen;
                }

                return $file;
            },
        );
        $this->job($pdo)->send('2026-11-25');

        return $filed;
    }

    private function job(PDO $pdo): ProbeBanDigestJob
    {
        return new ProbeBanDigestJob($pdo, new MailOutbox($pdo));
    }

    /**
     * @return array{address: string, reason: string, strikes: int, path: string, banned_at: string, banned_until: string, time_zone: string}
     */
    private function row(string $address, string $reason, int $strikes): array
    {
        return [
            'address' => $address,
            'reason' => $reason,
            'strikes' => $strikes,
            'path' => '/x.php',
            'banned_at' => '2026-11-24 13:02',
            'banned_until' => '2026-11-24 13:17',
            'time_zone' => 'America/Los_Angeles',
        ];
    }
}
