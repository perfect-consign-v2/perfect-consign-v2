<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Activity;

use PerfectConsign\Feature\Staff\MallOwners;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class ActivityPeople
{
    public function __construct(
        private readonly Statements $statements,
        private readonly MallOwners $owners,
    ) {
    }

    /**
     * @return array{
     *     current: list<array{id: string, label: string}>,
     *     former: list<array{id: string, label: string}>
     * }
     */
    public function list(TenantRecord $tenant): array
    {
        $current = $this->current($tenant);
        $known = [];
        foreach ($current as $person) {
            $known[$person['id']] = true;
        }

        return [
            'current' => $current,
            'former' => $this->former($known),
        ];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function current(TenantRecord $tenant): array
    {
        $people = [];
        foreach ($this->statements->bound(
            "SELECT id::text AS id, username, display_name
             FROM staff
             WHERE role IN ('cashier', 'manager', 'books')
               AND can_sign_in
             ORDER BY lower(display_name), username",
            [],
        ) as $row) {
            $person = $this->person(
                self::text($row['id'] ?? null),
                self::text($row['display_name'] ?? null),
                self::text($row['username'] ?? null),
            );
            if ($person !== null) {
                $people[] = $person;
            }
        }
        foreach ($this->owners->list($tenant) as $owner) {
            if (!$owner['can_sign_in']) {
                continue;
            }
            $person = $this->person($owner['id'], $owner['username'], $owner['username']);
            if ($person !== null) {
                $people[] = $person;
            }
        }

        return $this->sorted($people);
    }

    /**
     * @param array<string, true> $known
     * @return list<array{id: string, label: string}>
     */
    private function former(array $known): array
    {
        $people = [];
        foreach ($this->statements->bound(
            'SELECT DISTINCT ON (event.account_id)
                    event.account_id::text AS id, event.username, event.actor
             FROM audit_events AS event
             WHERE event.account_id IS NOT NULL
             ORDER BY event.account_id, event.at DESC, event.id DESC',
            [],
        ) as $row) {
            $id = self::text($row['id'] ?? null);
            if ($id === '' || isset($known[$id])) {
                continue;
            }
            $person = $this->person($id, self::text($row['actor'] ?? null), self::text($row['username'] ?? null));
            if ($person !== null) {
                $people[] = $person;
            }
        }

        return $this->sorted($people);
    }

    /**
     * @param list<array{id: string, label: string}> $people
     * @return list<array{id: string, label: string}>
     */
    private function sorted(array $people): array
    {
        usort($people, static function (array $left, array $right): int {
            return strcasecmp($left['label'], $right['label']);
        });

        return $people;
    }

    /**
     * @return array{id: string, label: string}|null
     */
    private function person(string $id, string $name, string $username): ?array
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1 || $username === '') {
            return null;
        }
        $label = $username;
        if ($name !== '' && strcasecmp($name, $username) !== 0) {
            $label = $name . ' (' . $username . ')';
        }

        return ['id' => $id, 'label' => $label];
    }

    private static function text(mixed $value): string
    {
        return RowValue::optionalString($value) ?? '';
    }
}
