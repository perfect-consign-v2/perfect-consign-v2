<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Jobs;

use PDO;
use PerfectConsign\Feature\Dev\LocalAdmin;
use PerfectConsign\Shared\Jobs\JobQueue;
use PerfectConsign\Shared\Mail\MailOutbox;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;

final class JobQueueTest extends TestCase
{
    public function testTheSameDayIsEnqueuedOnce(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = LocalAdmin::open()->pdo();
        $queue = new JobQueue();
        $queue->enqueue($pdo, 'mall', 'reconcile-reports', '2099-01-01');
        $queue->enqueue($pdo, 'mall', 'reconcile-reports', '2099-01-01');
        $count = $pdo->prepare(
            "SELECT count(*) FROM platform.jobs
             WHERE tenant = ? AND kind = 'reconcile-reports' AND payload ->> 'date' = '2099-01-01'"
        );
        $count->execute(['mall']);
        self::assertSame(1, (int) $count->fetchColumn());
        $done = $pdo->prepare(
            "DELETE FROM platform.jobs
             WHERE tenant = ? AND kind = 'reconcile-reports' AND payload ->> 'date' = '2099-01-01'"
        );
        $done->execute(['mall']);
    }

    public function testALetterIsEnqueuedOnce(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = LocalAdmin::open()->pdo();
        $schemaStatement = $pdo->prepare('SELECT schema_name FROM platform.tenants WHERE slug = ?');
        $schemaStatement->execute([ProbeMall::SLUG]);
        $schema = $schemaStatement->fetchColumn();
        self::assertIsString($schema);
        $role = TenantRecord::roleFor(ProbeMall::SLUG, $schema);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $pdo->exec('SET ROLE ' . $role);
        $queue = new JobQueue();
        $letter = '01900000-0000-7000-8000-00000000c201';
        $queue->enqueueLetter($pdo, '2099-06-01', $letter);
        $queue->enqueueLetter($pdo, '2099-06-02', $letter);
        $pdo->exec('RESET ROLE');
        $count = $pdo->prepare(
            "SELECT count(*) FROM platform.jobs
             WHERE tenant = ? AND kind = 'vendor-letter' AND payload ->> 'letter' = ?"
        );
        $count->execute([ProbeMall::SLUG, $letter]);
        self::assertSame(1, (int) $count->fetchColumn());
        $done = $pdo->prepare(
            "DELETE FROM platform.jobs
             WHERE tenant = ? AND kind = 'vendor-letter' AND payload ->> 'letter' = ?"
        );
        $done->execute([ProbeMall::SLUG, $letter]);
    }

    public function testOverdueIsThisMallAndMailThatAlreadyLeftDoesNotCount(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = LocalAdmin::open()->pdo();
        $queue = new JobQueue();
        $mailId = null;
        $clear = $pdo->prepare(
            "DELETE FROM platform.jobs
             WHERE tenant = 'mall' AND kind = 'reconcile-reports' AND payload ->> 'date' = '2099-02-02'"
        );
        $clear->execute();
        try {
            $mall = $queue->overdue($pdo, 'mall');
            $probe = $queue->overdue($pdo, ProbeMall::SLUG);
            $queue->enqueue($pdo, 'mall', 'reconcile-reports', '2099-02-02');
            $this->age($pdo, 'mall', 'reconcile-reports', '2099-02-02');
            self::assertSame($mall + 1, $queue->overdue($pdo, 'mall'));
            self::assertSame($probe, $queue->overdue($pdo, ProbeMall::SLUG));
            $mailId = (new MailOutbox($pdo))->file(
                'platform',
                MailOutbox::MAILBOX_PLATFORM,
                'queue probe',
                'queue-probe@example.test',
                'Queue probe',
                'Body',
            );
            $this->ageMail($pdo, $mailId);
            $platform = $queue->overdue($pdo, 'platform');
            self::assertGreaterThan(0, $platform);
            self::assertSame($mall + 1, $queue->overdue($pdo, 'mall'));
            (new MailOutbox($pdo))->markSent($mailId);
            self::assertSame($platform - 1, $queue->overdue($pdo, 'platform'));
        } finally {
            $done = $pdo->prepare(
                "DELETE FROM platform.jobs
                 WHERE tenant = 'mall' AND kind = 'reconcile-reports' AND payload ->> 'date' = '2099-02-02'"
            );
            $done->execute();
            if (is_string($mailId)) {
                $job = $pdo->prepare(
                    "DELETE FROM platform.jobs
                     WHERE tenant = 'platform' AND kind = 'outbound-mail' AND payload ->> 'mail' = ?"
                );
                $job->execute([$mailId]);
                $mail = $pdo->prepare('DELETE FROM platform.mail_outbox WHERE id = ?::uuid');
                $mail->execute([$mailId]);
            }
        }
    }

    private function age(PDO $pdo, string $tenant, string $kind, string $date): void
    {
        $statement = $pdo->prepare(
            "UPDATE platform.jobs
             SET run_at = clock_timestamp() - interval '1 hour'
             WHERE tenant = ? AND kind = ? AND payload ->> 'date' = ?"
        );
        $statement->execute([$tenant, $kind, $date]);
    }

    private function ageMail(PDO $pdo, string $mailId): void
    {
        $statement = $pdo->prepare(
            "UPDATE platform.jobs
             SET run_at = clock_timestamp() - interval '1 hour'
             WHERE tenant = 'platform' AND kind = 'outbound-mail' AND payload ->> 'mail' = ?"
        );
        $statement->execute([$mailId]);
    }
}
