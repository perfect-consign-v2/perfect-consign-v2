<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Schema;

use PerfectConsign\Schema\MigrationLock;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class MigrationLockTest extends TestCase
{
    public function testALiveTransactionIsLeftAlone(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('query')->willReturn($this->createStub(PDOStatement::class));
        $pdo->expects($this->never())->method('rollBack');

        (new MigrationLock($pdo))->release();
    }
}
