<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class JobRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly JobQueue $queue,
        private readonly LoggerInterface $logger,
        private readonly JobSchedule $schedule,
        private readonly LateFeeJob $lateFees,
        private readonly DigestJob $digest,
        private readonly ReconcileJob $reconcile,
        private readonly SaleNoticeJob $notices,
    ) {
    }

    public function pass(string $worker): void
    {
        $this->schedule->schedule();
        $overdue = $this->queue->overdue($this->pdo);
        if ($overdue > 0) {
            $this->logger->error('job is past run_at', ['overdue' => $overdue]);
        }
        while (($job = $this->queue->claim($this->pdo, $worker)) !== null) {
            $error = '';
            try {
                $this->handle($job);
            } catch (\Throwable $failure) {
                $error = $failure->getMessage();
                $this->logger->error('job failed', ['kind' => $job['kind'], 'tenant' => $job['tenant'], 'error' => $error]);
            }
            $this->queue->finish($this->pdo, $job['id'], $error);
        }
    }

    /**
     * @param array{id: string, tenant: string, kind: string, payload: string, attempts: int} $job
     */
    public function handle(array $job): void
    {
        $schema = $this->schema($job['tenant']);
        $this->pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $payload = json_decode($job['payload'], true);
        $date = is_array($payload) && is_string($payload['date'] ?? null) ? $payload['date'] : '';
        if ($job['kind'] === 'assess-late-fees') {
            $this->lateFees->lateFees($date);

            return;
        }
        if ($job['kind'] === 'vendor-digest') {
            $this->digest->digest();

            return;
        }
        if ($job['kind'] === 'rent-reminder') {
            $this->digest->remind();

            return;
        }
        if ($job['kind'] === 'reconcile-reports') {
            $this->reconcile->tie($date);
            $this->reconcile->reconcile();

            return;
        }
        if ($job['kind'] === 'sale-notice') {
            $this->notices->notice($date);

            return;
        }
        throw new RuntimeException('unknown job');
    }

    public function schema(string $slug): string
    {
        $statement = $this->pdo->prepare('SELECT schema_name FROM platform.tenants WHERE slug = ?');
        $statement->execute([$slug]);
        $schema = $statement->fetchColumn();
        if (!is_string($schema) || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new RuntimeException('tenant is not provisioned');
        }

        return $schema;
    }
}
