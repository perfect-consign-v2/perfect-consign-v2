<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Activity;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class ActivityLog
{
    public function __construct(private readonly Statements $statements)
    {
    }

    public function count(ActivityFilter $filter): int
    {
        $rows = $this->statements->bound(
            'SELECT count(*)::integer AS total
             FROM audit_events AS event
             CROSS JOIN mall
             WHERE ' . $this->where(),
            $this->params($filter),
        );

        return RowValue::integer($rows[0]['total'] ?? null);
    }

    /**
     * @return list<array{at: string, username: string, name: string, role: string, action: string, entity: string, authority: string, entered_by: string, address: string, recorded: bool}>
     */
    public function rows(ActivityFilter $filter, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }
        $listed = [];
        foreach (
            $this->statements->bound(
                'SELECT to_char(event.at AT TIME ZONE mall.time_zone, \'YYYY-MM-DD HH24:MI\') AS at,
                        event.username, event.actor, event.role, event.action,
                        event.entity_type, event.entity_id, event.authority_username,
                        event.entered_by_email, event.client_address, event.account_id::text AS account_id
                 FROM audit_events AS event
                 CROSS JOIN mall
                 WHERE ' . $this->where() . '
                 ORDER BY event.at DESC, event.id DESC
                 LIMIT ' . $limit,
                $this->params($filter),
            ) as $row
        ) {
            $recorded = is_string($row['account_id'] ?? null) && $row['account_id'] !== '';
            $listed[] = [
                'at' => self::text($row['at'] ?? null),
                'username' => $recorded ? self::text($row['username'] ?? null) : '',
                'name' => self::text($row['actor'] ?? null),
                'role' => self::text($row['role'] ?? null),
                'action' => self::text($row['action'] ?? null),
                'entity' => self::text($row['entity_type'] ?? null) . ' ' . self::text($row['entity_id'] ?? null),
                'authority' => self::text($row['authority_username'] ?? null),
                'entered_by' => self::text($row['entered_by_email'] ?? null),
                'address' => self::text($row['client_address'] ?? null),
                'recorded' => $recorded,
            ];
        }

        return $listed;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function where(): string
    {
        return '(?::date IS NULL OR (event.at AT TIME ZONE mall.time_zone)::date >= ?::date)
            AND (?::date IS NULL OR (event.at AT TIME ZONE mall.time_zone)::date <= ?::date)
            AND (?::uuid IS NULL OR event.account_id = ?::uuid)
            AND (?::text IS NULL OR event.action = ?)';
    }

    /**
     * @return list<string|null>
     */
    private function params(ActivityFilter $filter): array
    {
        return [
            $filter->from,
            $filter->from,
            $filter->to,
            $filter->to,
            $filter->accountId,
            $filter->accountId,
            $filter->action,
            $filter->action,
        ];
    }
}
