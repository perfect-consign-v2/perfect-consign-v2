<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Jobs;

use PerfectConsign\Feature\Dev\LocalAdmin;
use PerfectConsign\Shared\Jobs\JobQueue;
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
}
