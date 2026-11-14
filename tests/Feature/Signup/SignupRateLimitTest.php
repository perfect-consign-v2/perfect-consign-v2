<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Signup;

use PerfectConsign\Feature\Signup\SignupRateLimit;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SignupRateLimitTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('STALL_SIGNUP_MAX_ATTEMPTS');
        putenv('STALL_SIGNUP_WINDOW_SECONDS');
    }

    public function testFiveAttemptsInTheWindowAreRefused(): void
    {
        $pdo = $this->pdo(5, false);
        $pdo->expects($this->once())->method('commit');
        $pdo->expects($this->never())->method('rollBack');

        self::assertTrue((new SignupRateLimit($pdo))->refuse('203.0.113.5'));
    }

    public function testAFreshAddressIsRemembered(): void
    {
        putenv('STALL_SIGNUP_MAX_ATTEMPTS=3');
        putenv('STALL_SIGNUP_WINDOW_SECONDS=60');
        $inserted = false;
        $window = null;
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->expects($this->once())->method('commit');
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$inserted, &$window): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(static function (array $params) use ($sql, &$inserted, &$window): bool {
                if (str_contains($sql, 'INSERT INTO')) {
                    $inserted = true;
                }
                foreach ($params as $param) {
                    if ($param === 60) {
                        $window = 60;
                    }
                }

                return true;
            });
            $statement->method('fetchColumn')->willReturn(0);

            return $statement;
        });

        (new SignupRateLimit($pdo))->remember('203.0.113.8');

        self::assertTrue($inserted);
        self::assertSame(60, $window);
    }

    public function testAFailedClaimRollsBack(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack');
        $pdo->method('prepare')->willThrowException(new RuntimeException('lock failed'));

        $this->expectException(RuntimeException::class);
        (new SignupRateLimit($pdo))->refuse('203.0.113.9');
    }

    private function pdo(int $count, bool $insert): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction');
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($count, $insert): PDOStatement {
            if (str_contains($sql, 'INSERT INTO') && !$insert) {
                self::fail('an insert ran on a read');
            }
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('fetchColumn')->willReturn($count);

            return $statement;
        });

        return $pdo;
    }
}
