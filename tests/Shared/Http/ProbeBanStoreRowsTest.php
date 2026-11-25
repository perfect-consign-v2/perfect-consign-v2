<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PDO;
use PDOStatement;
use PerfectConsign\Shared\Http\ProbeBanStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProbeBanStoreRowsTest extends TestCase
{
    public function testUnreadableRowsAreLeftOffTheList(): void
    {
        $good = [
            'address' => '203.0.113.20',
            'banned_at' => '2026-11-24 12:00:00+00',
            'banned_until' => '2026-11-24 12:15:00+00',
            'active' => 'yes',
            'strikes' => '2',
            'reason' => 'streak',
            'path' => '/x.php',
        ];
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchAll')->willReturn([
            $good,
            'not a row',
            ['address' => '203.0.113.21'] + array_diff_key($good, ['path' => true]),
            ['strikes' => 'two'] + $good,
            ['active' => 'no', 'address' => '203.0.113.22'] + $good,
        ]);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn($statement);

        $bans = (new ProbeBanStore($pdo))->listBans();

        self::assertCount(2, $bans);
        self::assertSame('203.0.113.20', $bans[0]['address']);
        self::assertTrue($bans[0]['active']);
        self::assertSame(2, $bans[0]['strikes']);
        self::assertSame('203.0.113.22', $bans[1]['address']);
        self::assertFalse($bans[1]['active']);
    }

    public function testAHitOrBanAnswersOnlyTrueOrFalse(): void
    {
        self::assertFalse($this->store('false')->hit('203.0.113.23', 'browser', '/x.php'));
        self::assertTrue($this->store('true')->insertBan('203.0.113.23', 'scanner', 'browser', '/'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the probe hit was not stored');
        $this->store('t')->hit('203.0.113.23', 'browser', '/x.php');
    }

    private function store(string $answer): ProbeBanStore
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn($answer);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        return new ProbeBanStore($pdo);
    }
}
