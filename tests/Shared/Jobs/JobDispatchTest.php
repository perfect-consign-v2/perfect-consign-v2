<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Jobs;

use PDO;
use PDOStatement;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Jobs\DigestJob;
use PerfectConsign\Shared\Jobs\JobQueue;
use PerfectConsign\Shared\Jobs\JobRunner;
use PerfectConsign\Shared\Jobs\JobSchedule;
use PerfectConsign\Shared\Jobs\LateFeeJob;
use PerfectConsign\Shared\Jobs\ReconcileJob;
use PerfectConsign\Shared\Jobs\SaleNoticeJob;
use PerfectConsign\Shared\Mail\MailBounce;
use PerfectConsign\Shared\Mail\OutboundMail;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class JobDispatchTest extends TestCase
{
    public function testALateFeeJobUsesThePayloadDate(): void
    {
        try {
            $this->runner($this->pdo())->handle($this->job('assess-late-fees', '{"date":"2026-01-02"}'));
            self::fail('a dated job should read the mall');
        } catch (RuntimeException $error) {
            self::assertSame('the mall has no payout settings', $error->getMessage());
        }
    }

    public function testAnUnknownKindIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown job');
        $this->runner($this->pdo())->handle($this->job('not-a-job', '{}'));
    }

    public function testTheMallClockResetsTheRole(): void
    {
        $listed = $this->createStub(PDOStatement::class);
        $listed->method('fetch')->willReturnOnConsecutiveCalls(
            ['slug' => 'mall', 'schema_name' => 'not-a-schema'],
            false,
        );
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($listed);
        $pdo->expects($this->exactly(2))->method('exec')->willReturn(1);

        (new JobSchedule($pdo, new JobQueue()))->schedule();
    }

    private function pdo(): PDO
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn('tenant_' . str_repeat('ab', 16));
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('exec')->willReturn(1);
        $pdo->method('query')->willReturn(false);

        return $pdo;
    }

    private function runner(PDO $pdo): JobRunner
    {
        $logger = $this->createStub(LoggerInterface::class);
        $mail = new OutboundMail($logger);
        $bounce = new MailBounce($this->createStub(Statements::class));

        return new JobRunner(
            $pdo,
            new JobQueue(),
            $logger,
            new JobSchedule($pdo, new JobQueue()),
            new LateFeeJob($pdo),
            new DigestJob($pdo, $mail, $bounce, $logger),
            new ReconcileJob($pdo),
            new SaleNoticeJob($this->createStub(Statements::class), $mail, $bounce, $logger),
        );
    }

    /**
     * @return array{id: string, tenant: string, kind: string, payload: string, attempts: int}
     */
    private function job(string $kind, string $payload): array
    {
        return [
            'id' => '01900000-0000-7000-8000-000000000001',
            'tenant' => 'mall',
            'kind' => $kind,
            'payload' => $payload,
            'attempts' => 1,
        ];
    }
}
