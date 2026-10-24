<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class JobSchedule
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly JobQueue $queue,
    ) {
    }

    public function schedule(): void
    {
        $listed = $this->pdo->query('SELECT slug, schema_name FROM platform.tenants ORDER BY slug');
        if ($listed === false) {
            return;
        }
        while (($row = $listed->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $slug = $row['slug'] ?? null;
            $schema = $row['schema_name'] ?? null;
            if (!is_string($slug) || !is_string($schema)) {
                continue;
            }
            $clock = $this->mallClock($slug, $schema);
            if ($clock === null) {
                continue;
            }
            $this->queue->enqueue($this->pdo, $slug, 'assess-late-fees', $clock['date']);
            $this->queue->enqueue($this->pdo, $slug, 'reconcile-reports', $clock['date']);
            $this->queue->enqueue($this->pdo, $slug, 'sale-notice', $clock['date']);
            if ($clock['sunday']) {
                $this->queue->enqueue($this->pdo, $slug, 'vendor-digest', $clock['date']);
            }
            if ($clock['cycle']) {
                $this->queue->enqueue($this->pdo, $slug, 'rent-reminder', $clock['date']);
            }
        }
    }

    /**
     * @return array{date: string, sunday: bool, cycle: bool}|null
     */
    private function mallClock(string $slug, string $schema): ?array
    {
        $role = TenantRecord::roleFor($slug, $schema);
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            return null;
        }
        if ($this->pdo->exec('SET ROLE ' . $role) === false) {
            return null;
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
            return null;
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
        $leftText = self::numberText($left);
        $rightText = self::numberText($right);
        if ($leftText === null || $rightText === null) {
            return false;
        }

        return $leftText === $rightText;
    }

    private static function numberText(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/^-?\d+(\.0+)?$/', $value) === 1) {
            return (string) (int) $value;
        }

        return null;
    }
}
