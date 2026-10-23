<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;
use PerfectConsign\Shared\Scalar\RowValue;

final class JobQueue
{
    public const CLAIM = <<<'SQL'
        UPDATE platform.jobs AS job
        SET locked_by = ?, locked_at = clock_timestamp(), attempts = job.attempts + 1
        WHERE job.id = (
            SELECT waiting.id
            FROM platform.jobs AS waiting
            WHERE waiting.finished_at IS NULL
              AND waiting.run_at <= clock_timestamp()
              AND (waiting.locked_at IS NULL OR waiting.locked_at < clock_timestamp() - interval '15 minutes')
            ORDER BY waiting.run_at
            FOR UPDATE SKIP LOCKED
            LIMIT 1
        )
        RETURNING job.id, job.tenant, job.kind, job.payload::text AS payload, job.attempts
        SQL;

    public function enqueue(PDO $pdo, string $tenant, string $kind, string $date): void
    {
        $statement = $pdo->prepare(
            "INSERT INTO platform.jobs (tenant, kind, payload, run_at)
             VALUES (?, ?, jsonb_build_object('date', ?), clock_timestamp())
             ON CONFLICT (tenant, kind, (payload ->> 'date')) DO NOTHING"
        );
        $statement->execute([$tenant, $kind, $date]);
    }

    /**
     * @return array{id: string, tenant: string, kind: string, payload: string, attempts: int}|null
     */
    public function claim(PDO $pdo, string $worker): ?array
    {
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(self::CLAIM);
            $statement->execute([$worker]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        if (!is_array($row)) {
            return null;
        }
        $id = $row['id'] ?? null;
        $tenant = $row['tenant'] ?? null;
        $kind = $row['kind'] ?? null;
        $payload = $row['payload'] ?? null;
        if (!is_string($id) || !is_string($tenant) || !is_string($kind) || !is_string($payload)) {
            return null;
        }

        return [
            'id' => $id,
            'tenant' => $tenant,
            'kind' => $kind,
            'payload' => $payload,
            'attempts' => RowValue::integer($row['attempts'] ?? 0),
        ];
    }

    public function finish(PDO $pdo, string $id, string $error): void
    {
        if ($error === '') {
            $statement = $pdo->prepare(
                'UPDATE platform.jobs
                 SET finished_at = clock_timestamp(), locked_by = NULL, locked_at = NULL, error = NULL
                 WHERE id = ?::uuid'
            );
            $statement->execute([$id]);

            return;
        }
        $statement = $pdo->prepare(
            "UPDATE platform.jobs
             SET locked_by = NULL,
                 locked_at = NULL,
                 error = ?,
                 finished_at = CASE WHEN attempts >= 5 THEN clock_timestamp() ELSE NULL END,
                 run_at = CASE
                     WHEN attempts >= 5 THEN run_at
                     ELSE clock_timestamp() + (attempts * interval '1 minute')
                 END
             WHERE id = ?::uuid"
        );
        $statement->execute([$error, $id]);
    }

    public function overdue(PDO $pdo): int
    {
        $query = $pdo->query(
            "SELECT count(*) FROM platform.jobs
             WHERE finished_at IS NULL
               AND locked_at IS NULL
               AND run_at < clock_timestamp() - interval '15 minutes'"
        );
        if ($query === false) {
            return 0;
        }

        return (int) $query->fetchColumn();
    }
}
