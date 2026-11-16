<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Jobs;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use PerfectConsign\Shared\Jobs\JobQueue;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JobQueueFailLoudTest extends TestCase
{
    public function testOverdueQueryFailureThrows(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('job overdue count failed');
        (new JobQueue())->overdue($pdo);
    }

    public function testOverdueRefusesABadMall(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('query');
        $pdo->expects($this->never())->method('prepare');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the mall is not a mall');
        (new JobQueue())->overdue($pdo, 'Not A Mall');
    }

    public function testMalformedClaimRowThrows(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn([
            'id' => 1,
            'tenant' => 'mall',
            'kind' => 'reconcile-reports',
            'payload' => '{}',
            'attempts' => 1,
        ]);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('prepare')->willReturn($statement);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('job claim returned a malformed row');
        (new JobQueue())->claim($pdo, 'worker-1');
    }
}
