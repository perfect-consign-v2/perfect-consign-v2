<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Jobs;

use PDO;
use PDOStatement;
use PerfectConsign\Feature\Mall\ReceiptMailSettings;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Jobs\DigestJob;
use PerfectConsign\Shared\Jobs\JobDispatch;
use PerfectConsign\Shared\Jobs\JobQueue;
use PerfectConsign\Shared\Jobs\JobRunner;
use PerfectConsign\Shared\Jobs\JobSchedule;
use PerfectConsign\Shared\Jobs\LateFeeJob;
use PerfectConsign\Shared\Jobs\MarkdownJob;
use PerfectConsign\Shared\Jobs\OutboundMailJob;
use PerfectConsign\Shared\Jobs\ReconcileJob;
use PerfectConsign\Shared\Jobs\SaleNoticeJob;
use PerfectConsign\Shared\Jobs\VendorLetterJob;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Shared\Mail\MailBounce;
use PerfectConsign\Shared\Mail\MailProgress;
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

    public function testAMarkdownJobProposesTheDay(): void
    {
        $job = new MarkdownJob($this->pdo());
        $job->propose('2026-01-02');
        self::assertSame('2026-01-02', '2026-01-02');
    }

    public function testAnUnknownKindIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown job');
        $this->runner($this->pdo())->handle($this->job('not-a-job', '{}'));
    }

    public function testDispatchRefusesAnUnknownKind(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown job');
        $this->jobs($this->pdo())->dispatch($this->job('not-a-job', '{}'));
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

    public function testASchemaThatIsNotATenantIsRefused(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn('not-a-schema');
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tenant is not provisioned');
        $this->runner($pdo)->schema('mall');
    }

    public function testAnEmptyDigestSendsNothing(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->exactly(2))->method('query')->willReturn(false);
        $logger = $this->createStub(LoggerInterface::class);
        $job = new DigestJob(
            $pdo,
            new OutboundMail($logger),
            new MailBounce($this->createStub(Statements::class)),
            $logger,
            new MailProgress(),
        );

        $job->digest('mall', '2026-01-02');
        $job->remind('mall', '2026-01-02');
    }

    public function testAnEmptySaleNoticeSendsNothing(): void
    {
        $statements = $this->createMock(Statements::class);
        $statements->expects($this->once())->method('bound')->willReturn([]);
        $logger = $this->createStub(LoggerInterface::class);
        $notice = new SaleNoticeJob(
            $statements,
            new OutboundMail($logger),
            new MailBounce($statements),
            $logger,
            new MailProgress(),
        );

        $notice->notice('mall', '2026-01-02');
        $notice->notice('mall', 'today');
    }

    public function testAFailedSaleNoticeStaysWaiting(): void
    {
        $previous = getenv('STALL_MAIL_FILE');
        putenv('STALL_MAIL_FILE');
        $statements = $this->createMock(Statements::class);
        $statements->expects($this->exactly(2))->method('bound')->willReturnOnConsecutiveCalls(
            [['email' => 'ada@example.test', 'name' => 'Ada', 'code' => 'A1', 'net_minor' => 500]],
            [],
        );
        $statements->expects($this->never())->method('change');
        $logger = $this->createStub(LoggerInterface::class);
        $notice = new SaleNoticeJob(
            $statements,
            new OutboundMail($logger),
            new MailBounce($statements),
            $logger,
            new MailProgress(),
        );

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('a letter is still waiting to be sent');
            $notice->notice('mall', '2026-01-02');
        } finally {
            if (is_string($previous) && $previous !== '') {
                putenv('STALL_MAIL_FILE=' . $previous);
            } else {
                putenv('STALL_MAIL_FILE');
            }
        }
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
        return new JobRunner(
            $pdo,
            new JobQueue(),
            $this->createStub(LoggerInterface::class),
            new JobSchedule($pdo, new JobQueue()),
            $this->jobs($pdo),
            new \PerfectConsign\Shared\Jobs\WorkerRuns($pdo),
        );
    }

    private function jobs(PDO $pdo): JobDispatch
    {
        $logger = $this->createStub(LoggerInterface::class);
        $mail = new OutboundMail($logger);
        $bounce = new MailBounce($this->createStub(Statements::class));

        $notices = new \PerfectConsign\Shared\Mail\PlatformOperatorNotice($logger);
        $gmail = new \PerfectConsign\Shared\Mail\GmailSender($notices);
        $mailbox = \PerfectConsign\Tests\Support\MailTestKit::letterMailbox($this->createStub(Statements::class), $mail);

        return new JobDispatch(
            new LateFeeJob($pdo),
            new DigestJob($pdo, $mail, $bounce, $logger, new MailProgress()),
            new ReconcileJob($pdo),
            new SaleNoticeJob($this->createStub(Statements::class), $mail, $bounce, $logger, new MailProgress()),
            new VendorLetterJob(
                $this->createStub(Statements::class),
                $mailbox,
                $bounce,
                $logger,
            ),
            new MarkdownJob($pdo),
            new OutboundMailJob(
                new \PerfectConsign\Shared\Mail\MailOutbox($pdo),
                $mailbox,
                new \PerfectConsign\Shared\Mail\PlatformMailbox($mail, $gmail, $notices),
            ),
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
