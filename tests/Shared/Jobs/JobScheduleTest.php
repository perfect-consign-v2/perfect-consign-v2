<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Jobs;

use PDO;
use PDOStatement;
use PerfectConsign\Shared\Jobs\JobQueue;
use PerfectConsign\Shared\Jobs\JobSchedule;
use PHPUnit\Framework\TestCase;

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

        (new JobSchedule($pdo, new JobQueue()))->schedule();
    }
}
