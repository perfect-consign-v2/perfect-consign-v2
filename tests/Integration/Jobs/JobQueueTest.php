<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Jobs;

use PerfectConsign\Feature\Dev\LocalAdmin;
use PerfectConsign\Shared\Jobs\JobQueue;
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
}
