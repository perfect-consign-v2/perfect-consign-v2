<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\WaitList;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;
use Psr\Log\LoggerInterface;

final class WaitListStore
{
    public function __construct(
        private readonly Statements $statements,
        private readonly WaitListFields $fields,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     email: string,
     *     phone: string,
     *     size: string,
     *     status: string,
     *     status_label: string,
     *     status_badge: string,
     *     notes: string,
     *     waiting_since: string,
     *     waited: string
     * }>
     */
    public function entries(string $query, string $status): array
    {
        $where = [];
        $params = [];
        if ($status === 'all') {
            $where = [];
        } elseif (in_array($status, ['waiting', 'contacted', 'placed', 'declined'], true)) {
            $where[] = 'entry.status = ?';
            $params[] = $status;
        } else {
            $where[] = "entry.status IN ('waiting', 'contacted')";
        }
        $needle = strtolower(trim($query));
        if ($needle !== '') {
            $where[] = '(strpos(lower(entry.first_name), ?) > 0
                OR strpos(lower(entry.last_name), ?) > 0
                OR strpos(lower(btrim(entry.first_name || \' \' || entry.last_name)), ?) > 0
                OR strpos(lower(entry.email), ?) > 0
                OR strpos(lower(entry.phone), ?) > 0)';
            array_push($params, $needle, $needle, $needle, $needle, $needle);
        }
        $sql = $this->select();
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY entry.created_at, lower(entry.first_name), lower(entry.last_name), entry.id';
        $rows = $params === [] ? $this->statements->rows($sql) : $this->statements->bound($sql, $params);
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = $this->present($row);
        }

        return $entries;
    }

    public function count(): int
    {
        $rows = $this->statements->rows('SELECT count(*)::int AS total FROM wait_list');

        return RowValue::integer($rows[0]['total'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $rows = $this->statements->bound($this->select() . ' WHERE entry.id = ?::uuid', [$id]);

        return $rows[0] ?? null;
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     email: string,
     *     phone: string,
     *     size: string,
     *     status: string,
     *     status_label: string,
     *     status_badge: string,
     *     notes: string,
     *     waiting_since: string,
     *     waited: string
     * }|null
     */
    public function card(string $id): ?array
    {
        $row = $this->find($id);
        if ($row === null) {
            return null;
        }

        return $this->present($row);
    }

    public function add(
        string $firstName,
        string $lastName,
        string $email,
        string $phone,
        string $width,
        string $depth,
        string $notes,
    ): string {
        $fields = $this->fields->create($firstName, $lastName, $email, $phone, $width, $depth, $notes);
        $rows = $this->statements->bound(
            'INSERT INTO wait_list (first_name, last_name, email, phone, width_ft, depth_ft, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             RETURNING id',
            [
                $fields['first'],
                $fields['last'],
                $fields['email'],
                $fields['phone'],
                $fields['width'],
                $fields['depth'],
                $fields['notes'],
            ]
        );
        $id = $rows[0]['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \InvalidArgumentException('the wait list row was not created');
        }

        return $id;
    }

    public function save(
        string $id,
        string $firstName,
        string $lastName,
        string $email,
        string $phone,
        string $width,
        string $depth,
        string $status,
        string $notes,
    ): void {
        $fields = $this->fields->update(
            $firstName,
            $lastName,
            $email,
            $phone,
            $width,
            $depth,
            $status,
            $notes,
        );
        $count = $this->statements->affected(
            'UPDATE wait_list
             SET first_name = ?, last_name = ?, email = ?, phone = ?, width_ft = ?, depth_ft = ?, status = ?, notes = ?
             WHERE id = ?::uuid',
            [
                $fields['first'],
                $fields['last'],
                $fields['email'],
                $fields['phone'],
                $fields['width'],
                $fields['depth'],
                $fields['status'],
                $fields['notes'],
                $id,
            ]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('That person is not on the wait list.');
        }
    }

    public function remove(string $id): void
    {
        $count = $this->statements->affected('DELETE FROM wait_list WHERE id = ?::uuid', [$id]);
        if ($count === 0) {
            throw new \InvalidArgumentException('That person is not on the wait list.');
        }
    }

    private function select(): string
    {
        return 'SELECT entry.id, entry.first_name, entry.last_name, entry.email, entry.phone,
                       entry.width_ft, entry.depth_ft, entry.status, entry.notes,
                       to_char(entry.created_at AT TIME ZONE mall.time_zone, \'YYYY-MM-DD\') AS waiting_since,
                       ((clock_timestamp() AT TIME ZONE mall.time_zone)::date
                           - (entry.created_at AT TIME ZONE mall.time_zone)::date) AS waited_days
                FROM wait_list AS entry
                CROSS JOIN mall';
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *     id: string,
     *     name: string,
     *     email: string,
     *     phone: string,
     *     size: string,
     *     status: string,
     *     status_label: string,
     *     status_badge: string,
     *     notes: string,
     *     waiting_since: string,
     *     waited: string
     * }
     */
    private function present(array $row): array
    {
        $first = RowValue::string($row['first_name'] ?? null);
        $last = RowValue::string($row['last_name'] ?? null);
        $status = RowValue::string($row['status'] ?? null);
        $standing = $this->standing($status);

        return [
            'id' => RowValue::string($row['id'] ?? null),
            'name' => $first === $last ? $first : $first . ' ' . $last,
            'email' => RowValue::string($row['email'] ?? null),
            'phone' => RowValue::string($row['phone'] ?? null),
            'size' => $this->sizeLabel($row['width_ft'] ?? null, $row['depth_ft'] ?? null),
            'status' => $status,
            'status_label' => $standing['label'],
            'status_badge' => $standing['badge'],
            'notes' => $this->optional($row['notes'] ?? null),
            'waiting_since' => RowValue::string($row['waiting_since'] ?? null),
            'waited' => $this->waited($row['waited_days'] ?? null),
        ];
    }

    /**
     * @return array{label: string, badge: string}
     */
    private function standing(string $status): array
    {
        return match ($status) {
            'waiting' => ['label' => 'Waiting', 'badge' => 'badge-warning'],
            'contacted' => ['label' => 'Contacted', 'badge' => 'badge-accent'],
            'placed' => ['label' => 'Placed', 'badge' => 'badge-success'],
            'declined' => ['label' => 'Declined', 'badge' => ''],
            default => $this->unknown($status),
        };
    }

    /**
     * @return array{label: string, badge: string}
     */
    private function unknown(string $status): array
    {
        $this->logger->warning('wait list status is not one of the four', ['status' => $status]);

        return ['label' => $status, 'badge' => ''];
    }

    private function sizeLabel(mixed $width, mixed $depth): string
    {
        if ($width === null || $depth === null || $width === '' || $depth === '') {
            return 'Any';
        }

        return RowValue::integer($width) . ' × ' . RowValue::integer($depth) . ' ft';
    }

    private function waited(mixed $days): string
    {
        $count = RowValue::integer($days);
        if ($count <= 0) {
            return 'Today';
        }
        if ($count === 1) {
            return '1 day';
        }

        return $count . ' days';
    }

    private function optional(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return RowValue::string($value);
    }
}
