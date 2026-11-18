<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Jobs;

use PDO;
use PDOStatement;
use PerfectConsign\Shared\Jobs\JobQueue;
use PerfectConsign\Shared\Jobs\JobSchedule;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class JobScheduleTest extends TestCase
{
    public function testARowWithoutASchemaIsSkipped(): void
    {
        $listed = $this->createStub(PDOStatement::class);
        $listed->method('fetch')->willReturnOnConsecutiveCalls(
            ['slug' => 'demo', 'schema_name' => 4],
            false
        );
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');
        $pdo->method('query')->willReturn($listed);

        (new JobSchedule($pdo, new JobQueue(), new NullLogger()))->schedule();
    }

    public function testTenantListQueryFailureThrows(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('job schedule tenant list failed');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('job schedule tenant list failed');
        (new JobSchedule($pdo, new JobQueue(), $logger))->schedule();
    }

    public function testTheSundayLetterIsFiledOnlyWhenTheStoreTurnedItOn(): void
    {
        self::assertNotContains('vendor-digest', $this->sundayKinds(false));
        self::assertContains('sale-notice', $this->sundayKinds(false));
        self::assertContains('vendor-digest', $this->sundayKinds(true));
    }

    /**
     * @return list<string>
     */
    private function sundayKinds(bool $weekly): array
    {
        $schema = 'tenant_' . str_repeat('e', 32);
        $listed = $this->createStub(PDOStatement::class);
        $listed->method('fetch')->willReturnOnConsecutiveCalls(
            ['slug' => 'mall', 'schema_name' => $schema],
            false,
        );
        $clock = $this->createStub(PDOStatement::class);
        $clock->method('fetch')->willReturn([
            'day' => '2026-11-08',
            'dow' => '0',
            'month_day' => '8',
            'rent_cycle_day' => 1,
            'vendor_weekly_mail_enabled' => $weekly ? 't' : 'f',
        ]);
        $kinds = [];
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturnCallback(static function (array $params) use (&$kinds): bool {
            $kind = $params[1] ?? null;
            if (is_string($kind)) {
                $kinds[] = $kind;
            }

            return true;
        });
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturnCallback(static function (string $sql) use ($listed, $clock): PDOStatement {
            if (str_contains($sql, 'platform.tenants')) {
                return $listed;
            }

            return $clock;
        });
        $pdo->method('exec')->willReturn(1);
        $pdo->method('prepare')->willReturn($statement);
        (new JobSchedule($pdo, new JobQueue(), new NullLogger()))->schedule();

        return $kinds;
    }
}
