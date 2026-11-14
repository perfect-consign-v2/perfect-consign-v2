<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;
use PerfectConsign\Shared\Tenant\TenantRecord;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class JobSchedule
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly JobQueue $queue,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function schedule(): void
    {
        $listed = $this->pdo->query('SELECT slug, schema_name FROM platform.tenants ORDER BY slug');
        if ($listed === false) {
            $this->logger->error('job schedule tenant list failed');
            throw new RuntimeException('job schedule tenant list failed');
        }
        while (($row = $listed->fetch(PDO::FETCH_ASSOC)) !== false) {
            $this->enqueueTenant($row);
        }
    }

    private function enqueueTenant(mixed $row): void
    {
        if (!is_array($row)) {
            return;
        }
        $slug = $row['slug'] ?? null;
        $schema = $row['schema_name'] ?? null;
        if (!is_string($slug) || !is_string($schema)) {
            $this->logger->warning('job schedule skipped tenant with invalid row', [
                'slug' => $this->slugText($slug),
            ]);

            return;
        }
        $clock = $this->mallClock($slug, $schema);
        if ($clock === null) {
            return;
        }
        $this->enqueueDaily($slug, $clock);
    }

    /**
     * @param array{date: string, sunday: bool, cycle: bool} $clock
     */
    private function enqueueDaily(string $slug, array $clock): void
    {
        $this->queue->enqueue($this->pdo, $slug, 'assess-late-fees', $clock['date']);
        $this->queue->enqueue($this->pdo, $slug, 'reconcile-reports', $clock['date']);
        $this->queue->enqueue($this->pdo, $slug, 'sale-notice', $clock['date']);
        $this->queue->enqueue($this->pdo, $slug, 'markdown-propose', $clock['date']);
        if ($clock['sunday']) {
            $this->queue->enqueue($this->pdo, $slug, 'vendor-digest', $clock['date']);
        }
        if ($clock['cycle']) {
            $this->queue->enqueue($this->pdo, $slug, 'rent-reminder', $clock['date']);
        }
    }

    private function slugText(mixed $slug): ?string
    {
        if (!is_string($slug)) {
            return null;
        }

        return $slug;
    }

    /**
     * @return array{date: string, sunday: bool, cycle: bool}|null
     */
    private function mallClock(string $slug, string $schema): ?array
    {
        $role = TenantRecord::roleFor($slug, $schema);
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            $this->logger->warning('job schedule skipped tenant with invalid role', [
                'slug' => $slug,
                'schema' => $schema,
                'role' => $role,
            ]);

            return null;
        }
        if ($this->pdo->exec('SET ROLE ' . $role) === false) {
            $this->logger->error('job schedule set role failed', [
                'slug' => $slug,
                'role' => $role,
            ]);
            throw new RuntimeException('job schedule set role failed for ' . $slug);
        }
        try {
            return $this->clock($schema);
        } finally {
            $this->pdo->exec('RESET ROLE');
        }
    }

    /**
     * @return array{date: string, sunday: bool, cycle: bool}|null
     */
    public function clock(string $schema): ?array
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            return null;
        }
        $query = $this->pdo->query(
            'SELECT (clock_timestamp() AT TIME ZONE time_zone)::date AS day,
                    EXTRACT(DOW FROM (clock_timestamp() AT TIME ZONE time_zone)) AS dow,
                    EXTRACT(DAY FROM (clock_timestamp() AT TIME ZONE time_zone)) AS month_day,
                    rent_cycle_day
             FROM ' . $schema . '.mall'
        );
        if ($query === false) {
            $this->logger->error('job schedule mall clock failed', ['schema' => $schema]);
            throw new RuntimeException('job schedule mall clock failed for ' . $schema);
        }
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $day = $row['day'] ?? null;
        if (!is_string($day) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return null;
        }
        $dow = $row['dow'] ?? null;

        return [
            'date' => $day,
            'sunday' => $dow === 0 || $dow === '0' || $dow === '0.0',
            'cycle' => self::sameNumber($row['month_day'] ?? null, $row['rent_cycle_day'] ?? null),
        ];
    }

    private static function sameNumber(mixed $left, mixed $right): bool
    {
        if (!is_numeric($left) || !is_numeric($right)) {
            return false;
        }

        return (int) $left === (int) $right;
    }
}
