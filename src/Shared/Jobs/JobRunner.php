<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;
use PerfectConsign\Shared\Mail\MailDeliveryFailed;
use PerfectConsign\Shared\Tenant\TenantRecord;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class JobRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly JobQueue $queue,
        private readonly LoggerInterface $logger,
        private readonly JobSchedule $schedule,
        private readonly JobDispatch $jobs,
        private readonly WorkerRuns $runs,
        private readonly JobAttention $attention,
    ) {
    }

    public function pass(string $worker): void
    {
        $finished = 0;
        $failed = 0;
        try {
            $this->schedule->schedule();
            $overdue = $this->queue->overdue($this->pdo);
            if ($overdue > 0) {
                $this->logger->error('job is past run_at', ['overdue' => $overdue]);
            }
            while (($job = $this->queue->claim($this->pdo, $worker)) !== null) {
                $error = '';
                $failure = null;
                try {
                    $this->handle($job);
                } catch (\Throwable $caught) {
                    $failure = $caught;
                    $error = $caught->getMessage();
                    $this->logger->error('job failed', MailDeliveryFailed::context($caught, [
                        'kind' => $job['kind'],
                        'tenant' => $job['tenant'],
                        'error' => $error,
                    ]));
                }
                $this->queue->finish($this->pdo, $job['id'], $error);
                $finished++;
                if ($error !== '') {
                    $failed++;
                }
                if ($failure !== null && $job['attempts'] >= 5) {
                    $this->logger->error('job will not be retried', MailDeliveryFailed::context($failure, [
                        'kind' => $job['kind'],
                        'tenant' => $job['tenant'],
                        'error' => $error,
                    ]));
                }
            }
        } finally {
            $this->reportAttention();
            $this->runs->completePass($worker, $finished, $failed);
        }
    }

    private function reportAttention(): void
    {
        try {
            $this->attention->report();
        } catch (\Throwable $error) {
            $this->logger->error('job attention was not sent', MailDeliveryFailed::context($error, [
                'message' => $error->getMessage(),
            ]));
        }
    }

    /**
     * @param array{id: string, tenant: string, kind: string, payload: string, attempts: int} $job
     */
    public function handle(array $job): void
    {
        if ($job['tenant'] === 'platform') {
            if ($job['kind'] !== 'outbound-mail') {
                throw new RuntimeException('platform jobs are mail only');
            }
            $this->jobs->dispatch($job);

            return;
        }
        $schema = $this->schema($job['tenant']);
        $role = TenantRecord::roleFor($job['tenant'], $schema);
        if ($this->pdo->exec('SET ROLE ' . $role) === false) {
            throw new RuntimeException('the worker could not assume the mall role');
        }
        try {
            if ($this->pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions') === false) {
                throw new RuntimeException('tenant is not provisioned');
            }
            $this->jobs->dispatch($job);
        } finally {
            $this->pdo->exec('RESET ROLE');
        }
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
