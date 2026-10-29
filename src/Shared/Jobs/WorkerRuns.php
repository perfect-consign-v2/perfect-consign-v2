<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;
use PerfectConsign\Shared\Scalar\RowValue;
use RuntimeException;

final class WorkerRuns
{
    public const AUTO_PRUNE_DAYS = 14;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function heartbeat(string $worker, int $finished = 0, int $failed = 0): void
    {
        $worker = $this->workerName($worker);
        if ($finished < 0 || $failed < 0) {
            throw new RuntimeException('the worker run counts are wrong');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO platform.worker_heartbeat (worker, last_jobs_finished, last_jobs_failed)
             VALUES (?, ?, ?)
             ON CONFLICT (worker) DO UPDATE
             SET seen_at = clock_timestamp(),
                 last_jobs_finished = EXCLUDED.last_jobs_finished,
                 last_jobs_failed = EXCLUDED.last_jobs_failed'
        );
        $statement->execute([$worker, $finished, $failed]);
    }

    public function record(string $worker, int $finished, int $failed): void
    {
        $worker = $this->workerName($worker);
        if ($finished < 0 || $failed < 0) {
            throw new RuntimeException('the worker run counts are wrong');
        }
        if ($finished === 0 && $failed === 0) {
            return;
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO platform.worker_runs (worker, finished_at, jobs_finished, jobs_failed)
             VALUES (?, clock_timestamp(), ?, ?)'
        );
        $statement->execute([$worker, $finished, $failed]);
    }

    public function completePass(string $worker, int $finished, int $failed): void
    {
        $this->heartbeat($worker, $finished, $failed);
        $this->record($worker, $finished, $failed);
        $this->pruneOlderThanDays(self::AUTO_PRUNE_DAYS);
    }

    /**
     * @return list<array{
     *     id: string,
     *     worker: string,
     *     started_at: string,
     *     finished_at: string,
     *     jobs_finished: int,
     *     jobs_failed: int
     * }>
     */
    public function recent(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            $limit = 100;
        }
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, worker, started_at::text AS started_at,
                    COALESCE(finished_at::text, \'\') AS finished_at,
                    jobs_finished, jobs_failed
             FROM platform.worker_runs
             ORDER BY started_at DESC
             LIMIT ?'
        );
        $statement->execute([$limit]);
        $rows = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'worker' => RowValue::string($row['worker'] ?? null),
                'started_at' => RowValue::string($row['started_at'] ?? null),
                'finished_at' => RowValue::string($row['finished_at'] ?? null),
                'jobs_finished' => (int) ($row['jobs_finished'] ?? 0),
                'jobs_failed' => (int) ($row['jobs_failed'] ?? 0),
            ];
        }

        return $rows;
    }

    public function clear(): int
    {
        $deleted = $this->pdo->exec('DELETE FROM platform.worker_runs');
        if ($deleted === false) {
            throw new RuntimeException('the worker run log could not be cleared');
        }

        return $deleted;
    }

    public function pruneOlderThanDays(int $days): int
    {
        if ($days < 1) {
            throw new RuntimeException('the prune window is wrong');
        }
        $statement = $this->pdo->prepare(
            'DELETE FROM platform.worker_runs
             WHERE started_at < clock_timestamp() - make_interval(days => ?)'
        );
        $statement->execute([$days]);

        return $statement->rowCount();
    }

    /**
     * @return array{
     *     password_configured: bool,
     *     last_seen_at: string,
     *     last_jobs_finished: int,
     *     last_jobs_failed: int,
     *     waiting_mail: int,
     *     overdue_jobs: int,
     *     auto_prune_days: int
     * }
     */
    public function status(): array
    {
        $password = getenv('STALL_WORKER_PASSWORD');
        $last = $this->pdo->query(
            'SELECT seen_at::text AS seen_at, last_jobs_finished, last_jobs_failed
             FROM platform.worker_heartbeat
             ORDER BY seen_at DESC
             LIMIT 1'
        );
        $row = $last === false ? false : $last->fetch(PDO::FETCH_ASSOC);
        $waiting = (int) $this->pdo->query(
            'SELECT count(*) FROM platform.mail_outbox WHERE sent_at IS NULL'
        )->fetchColumn();
        $overdue = (int) $this->pdo->query(
            "SELECT count(*)
             FROM platform.jobs
             WHERE finished_at IS NULL
               AND run_at <= clock_timestamp()
               AND locked_at IS NULL"
        )->fetchColumn();

        return [
            'password_configured' => is_string($password) && $password !== '',
            'last_seen_at' => is_array($row) ? RowValue::string($row['seen_at'] ?? null) : '',
            'last_jobs_finished' => is_array($row) ? (int) ($row['last_jobs_finished'] ?? 0) : 0,
            'last_jobs_failed' => is_array($row) ? (int) ($row['last_jobs_failed'] ?? 0) : 0,
            'waiting_mail' => $waiting,
            'overdue_jobs' => $overdue,
            'auto_prune_days' => self::AUTO_PRUNE_DAYS,
        ];
    }

    private function workerName(string $worker): string
    {
        $worker = trim($worker);
        if ($worker === '' || strlen($worker) > 64) {
            throw new RuntimeException('the worker name is missing');
        }

        return $worker;
    }
}
