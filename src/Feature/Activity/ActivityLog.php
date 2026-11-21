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
     * @return list<array{
     *     id: string,
     *     at: string,
     *     username: string,
     *     name: string,
     *     role: string,
     *     action: string,
     *     entity_type: string,
     *     entity_id: string,
     *     entity: string,
     *     authority: string,
     *     entered_by: string,
     *     address: string,
     *     recorded: bool
     * }>
     */
    public function rows(ActivityFilter $filter, int $limit): array
    {
        return $this->fetch($filter, $limit, '', null);
    }

    /**
     * @return array{
     *     rows: list<array{
     *         id: string,
     *         at: string,
     *         username: string,
     *         name: string,
     *         role: string,
     *         action: string,
     *         entity_type: string,
     *         entity_id: string,
     *         entity: string,
     *         authority: string,
     *         entered_by: string,
     *         address: string,
     *         recorded: bool
     *     }>,
     *     older: string|null,
     *     newer: string|null
     * }|null
     */
    public function page(ActivityFilter $filter, int $size): ?array
    {
        $direction = $this->direction($filter);
        $bound = null;
        if ($direction !== '') {
            $bound = $this->locate($filter);
            if ($bound === null) {
                return null;
            }
        }
        if ($size < 1) {
            return ['rows' => [], 'older' => null, 'newer' => null];
        }
        $fetched = $this->fetch($filter, $size + 1, $direction, $bound);
        $extra = count($fetched) > $size;
        if ($extra) {
            array_pop($fetched);
        }
        if ($direction === 'after') {
            $fetched = array_values(array_reverse($fetched));
        }

        return [
            'rows' => $fetched,
            'older' => $this->older($direction, $fetched, $extra, $bound),
            'newer' => $this->newer($direction, $fetched, $extra, $bound),
        ];
    }

    /**
     * @param array{at: string, id: string}|null $bound
     * @return list<array{
     *     id: string,
     *     at: string,
     *     username: string,
     *     name: string,
     *     role: string,
     *     action: string,
     *     entity_type: string,
     *     entity_id: string,
     *     entity: string,
     *     authority: string,
     *     entered_by: string,
     *     address: string,
     *     recorded: bool
     * }>
     */
    private function fetch(ActivityFilter $filter, int $limit, string $direction, ?array $bound): array
    {
        if ($limit < 1) {
            return [];
        }
        $listed = [];
        foreach ($this->statements->bound($this->sql($direction, $limit), $this->bound($filter, $bound)) as $row) {
            $listed[] = $this->mapped($row);
        }

        return $listed;
    }

    private function sql(string $direction, int $limit): string
    {
        $order = $direction === 'after' ? 'ASC' : 'DESC';

        return 'SELECT event.id::text AS id,
                       to_char(event.at AT TIME ZONE mall.time_zone, \'YYYY-MM-DD HH24:MI\') AS at,
                       event.username, event.actor, event.role, event.action,
                       event.entity_type, event.entity_id, event.authority_username,
                       event.entered_by_email, event.client_address, event.account_id::text AS account_id
                FROM audit_events AS event
                CROSS JOIN mall
                WHERE ' . $this->where() . $this->cursor($direction) . '
                ORDER BY event.at ' . $order . ', event.id ' . $order . '
                LIMIT ' . $limit;
    }

    /**
     * @return array{at: string, id: string}|null
     */
    private function locate(ActivityFilter $filter): ?array
    {
        $id = $filter->before ?? $filter->after;
        if ($id === null) {
            return null;
        }
        $rows = $this->statements->bound(
            'SELECT event.at AS at, event.id::text AS id
             FROM audit_events AS event
             CROSS JOIN mall
             WHERE event.id = ?::uuid
               AND ' . $this->where(),
            array_merge([$id], $this->params($filter)),
        );
        if ($rows === []) {
            return null;
        }
        $at = self::instant($rows[0]['at'] ?? null);
        $found = self::text($rows[0]['id'] ?? null);
        if ($at === '' || $found === '') {
            return null;
        }

        return ['at' => $at, 'id' => $found];
    }

    private function direction(ActivityFilter $filter): string
    {
        if ($filter->before !== null) {
            return 'before';
        }
        if ($filter->after !== null) {
            return 'after';
        }

        return '';
    }

    /**
     * @param list<array{id: string}> $rows
     * @param array{id: string}|null $bound
     */
    private function older(string $direction, array $rows, bool $extra, ?array $bound): ?string
    {
        if ($direction === 'after') {
            return $this->edge($rows, false) ?? $this->boundId($bound);
        }
        if (!$extra) {
            return null;
        }

        return $this->edge($rows, false);
    }

    /**
     * @param list<array{id: string}> $rows
     * @param array{id: string}|null $bound
     */
    private function newer(string $direction, array $rows, bool $extra, ?array $bound): ?string
    {
        if ($direction === 'after') {
            if (!$extra) {
                return null;
            }

            return $this->edge($rows, true);
        }
        if ($direction !== 'before') {
            return null;
        }

        return $this->edge($rows, true) ?? $this->boundId($bound);
    }

    /**
     * @param array{id: string}|null $bound
     */
    private function boundId(?array $bound): ?string
    {
        if ($bound === null) {
            return null;
        }

        return $bound['id'];
    }

    /**
     * @param list<array{id: string}> $rows
     */
    private function edge(array $rows, bool $newest): ?string
    {
        if ($rows === []) {
            return null;
        }
        $row = $newest ? $rows[0] : $rows[array_key_last($rows)];

        return $row['id'];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *     id: string,
     *     at: string,
     *     username: string,
     *     name: string,
     *     role: string,
     *     action: string,
     *     entity_type: string,
     *     entity_id: string,
     *     entity: string,
     *     authority: string,
     *     entered_by: string,
     *     address: string,
     *     recorded: bool
     * }
     */
    private function mapped(array $row): array
    {
        $recorded = is_string($row['account_id'] ?? null) && $row['account_id'] !== '';
        $type = self::text($row['entity_type'] ?? null);
        $entityId = self::text($row['entity_id'] ?? null);

        return [
            'id' => RowValue::string($row['id'] ?? null),
            'at' => self::text($row['at'] ?? null),
            'username' => $recorded ? self::text($row['username'] ?? null) : '',
            'name' => self::text($row['actor'] ?? null),
            'role' => self::text($row['role'] ?? null),
            'action' => self::text($row['action'] ?? null),
            'entity_type' => $type,
            'entity_id' => $entityId,
            'entity' => trim($type . ' ' . $entityId),
            'authority' => self::text($row['authority_username'] ?? null),
            'entered_by' => self::text($row['entered_by_email'] ?? null),
            'address' => self::text($row['client_address'] ?? null),
            'recorded' => $recorded,
        ];
    }

    private function where(): string
    {
        return '(?::date IS NULL OR event.at >= ((?::date)::timestamp AT TIME ZONE mall.time_zone))
            AND (?::date IS NULL OR event.at < (((?::date + 1)::timestamp) AT TIME ZONE mall.time_zone))
            AND (?::uuid IS NULL OR event.account_id = ?::uuid)
            AND (?::text IS NULL OR event.action = ?)';
    }

    private function cursor(string $direction): string
    {
        if ($direction === 'before') {
            return ' AND (event.at, event.id) < (?::timestamptz, ?::uuid)';
        }
        if ($direction === 'after') {
            return ' AND (event.at, event.id) > (?::timestamptz, ?::uuid)';
        }

        return '';
    }

    /**
     * @param array{at: string, id: string}|null $bound
     * @return list<string|null>
     */
    private function bound(ActivityFilter $filter, ?array $bound): array
    {
        $params = $this->params($filter);
        if ($bound !== null) {
            $params[] = $bound['at'];
            $params[] = $bound['id'];
        }

        return $params;
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

    private static function instant(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.uP');
        }

        return is_string($value) ? $value : '';
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
