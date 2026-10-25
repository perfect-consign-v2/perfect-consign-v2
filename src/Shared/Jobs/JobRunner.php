<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;
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
            if ($error !== '' && $job['attempts'] >= 5) {
                $this->logger->error('job will not be retried', [
                    'kind' => $job['kind'],
                    'tenant' => $job['tenant'],
                    'error' => $error,
                ]);
            }
        }
    }

    /**
     * @param array{id: string, tenant: string, kind: string, payload: string, attempts: int} $job
     */
    public function handle(array $job): void
    {
        $schema = $this->schema($job['tenant']);
        $role = TenantRecord::roleFor($job['tenant'], $schema);
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new RuntimeException('tenant is not provisioned');
        }
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
