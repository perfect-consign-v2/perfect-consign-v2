<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\WaitList;

use PerfectConsign\Feature\Party\PartyIdentity;
use PerfectConsign\Feature\Party\PartyRoleType;
use PerfectConsign\Feature\Party\PersonPlace;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;
use Psr\Log\LoggerInterface;

final class WaitListStore
{
    private readonly PartyIdentity $identity;

    public function __construct(
        private readonly Statements $statements,
        private readonly WaitListFields $fields,
        private readonly LoggerInterface $logger,
        ?PartyIdentity $identity = null,
    ) {
        $this->identity = $identity ?? new PartyIdentity($statements);
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
            $where[] = '(strpos(lower(person.first_name), ?) > 0
                OR strpos(lower(person.last_name), ?) > 0
                OR strpos(lower(stall_person_display_name(person.first_name, person.last_name, person.display_name)), ?) > 0
                OR strpos(lower(COALESCE(stall_party_primary_email(entry.party_id), \'\')), ?) > 0
                OR strpos(lower(COALESCE(stall_party_primary_phone(entry.party_id), \'\')), ?) > 0)';
            array_push($params, $needle, $needle, $needle, $needle, $needle);
        }
        $sql = $this->select();
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY entry.created_at, lower(person.first_name), lower(person.last_name), entry.party_id';
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
     * @return array{open: int, waiting: int, contacted: int, placed: int, declined: int}
     */
    public function counts(): array
    {
        $counts = ['waiting' => 0, 'contacted' => 0, 'placed' => 0, 'declined' => 0];
        foreach ($this->statements->rows('SELECT status, count(*)::int AS total FROM wait_list GROUP BY status') as $row) {
            $status = RowValue::string($row['status'] ?? null);
            if (!array_key_exists($status, $counts)) {
                continue;
            }
            $counts[$status] = RowValue::integer($row['total'] ?? 0);
        }
        $counts['open'] = $counts['waiting'] + $counts['contacted'];

        return $counts;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $rows = $this->statements->bound($this->select() . ' WHERE entry.party_id = ?::uuid', [$id]);

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

        $partyId = '';
        $this->statements->transaction(function () use ($fields, &$partyId): void {
            $created = $this->identity->createPerson(
                $fields['first'],
                $fields['last'],
                null,
                $fields['phone'],
                $fields['email'],
                PersonPlace::empty(),
                PartyRoleType::WAIT_LIST,
            );
            $this->statements->change(
                'INSERT INTO wait_list (party_id, width_ft, depth_ft, notes)
                 VALUES (?::uuid, ?, ?, ?)',
                [
                    $created['party_id'],
                    $fields['width'],
                    $fields['depth'],
                    $fields['notes'],
                ]
            );
            $partyId = $created['party_id'];
        });

        return $partyId;
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
        $this->statements->transaction(function () use ($id, $fields): void {
            $onList = $this->statements->bound(
                'SELECT 1 FROM wait_list WHERE party_id = ?::uuid',
                [$id]
            );
            if ($onList === []) {
                throw new \InvalidArgumentException('That person is not on the wait list.');
            }
            $place = $this->identity->place($id);
            if ($place === null) {
                $place = [
                    'address' => null,
                    'city' => null,
                    'state' => null,
                    'postal' => null,
                ];
            }
            // Contacts before wait_list so the wait_list trigger sees the new primary email/phone.
            $this->identity->savePerson(
                $id,
                $fields['first'],
                $fields['last'],
                null,
                $fields['phone'],
                $fields['email'],
                new PersonPlace(
                    $place['address'],
                    $place['city'],
                    $place['state'],
                    $place['postal'],
                ),
            );
            $count = $this->statements->affected(
                'UPDATE wait_list
                 SET width_ft = ?, depth_ft = ?, status = ?, notes = ?
                 WHERE party_id = ?::uuid',
                [
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
        });
    }

    public function remove(string $id): void
    {
        $count = $this->statements->affected('DELETE FROM wait_list WHERE party_id = ?::uuid', [$id]);
        if ($count === 0) {
            throw new \InvalidArgumentException('That person is not on the wait list.');
        }
    }

    private function select(): string
    {
        return "SELECT entry.party_id AS id, person.first_name, person.last_name,
                       stall_party_primary_email(entry.party_id) AS email,
                       stall_party_primary_phone(entry.party_id) AS phone,
                       entry.width_ft, entry.depth_ft, entry.status, entry.notes,
                       to_char(entry.created_at AT TIME ZONE mall.time_zone, 'YYYY-MM-DD') AS waiting_since,
                       ((clock_timestamp() AT TIME ZONE mall.time_zone)::date
                           - (entry.created_at AT TIME ZONE mall.time_zone)::date) AS waited_days
                FROM wait_list AS entry
                JOIN persons AS person ON person.party_id = entry.party_id
                CROSS JOIN mall";
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
