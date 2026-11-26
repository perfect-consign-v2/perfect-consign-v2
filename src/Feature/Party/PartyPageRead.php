<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Feature\Inventory\ItemPageWindow;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * The parties list one page at a time, so no party is ever left off the list.
 * SQL fragments come from allowlists, never from the request.
 */
final class PartyPageRead
{
    private const string LISTED = "WITH listed AS (
            SELECT party.id,
                   COALESCE(stall_party_display_name(party.id), '') AS name,
                   CASE WHEN person.party_id IS NOT NULL THEN 'person' ELSE 'organization' END AS kind,
                   COALESCE(string_agg(roles.role_type, ', ' ORDER BY roles.role_type), '') AS roles
            FROM parties AS party
            LEFT JOIN persons AS person ON person.party_id = party.id
            LEFT JOIN party_roles AS roles ON roles.party_id = party.id AND roles.status = 'active'
            GROUP BY party.id, person.party_id
        ) ";

    /** @var array<string, string> */
    private const array ORDER = [
        'name' => 'lower(listed.name) %s, listed.id ASC',
        'kind' => 'listed.kind %s, lower(listed.name) ASC, listed.id ASC',
        'roles' => 'listed.roles %s, lower(listed.name) ASC, listed.id ASC',
    ];

    public function __construct(private readonly Statements $statements)
    {
    }

    private function total(): int
    {
        $rows = $this->statements->rows('SELECT count(*)::int AS total FROM parties');

        return RowValue::integer($rows[0]['total'] ?? 0);
    }

    public function page(PartyPageQuery $query, bool $consignmentOnly): PartyPage
    {
        [$where, $params] = $this->where($query, $consignmentOnly);
        $counted = $this->statements->bound(
            self::LISTED . 'SELECT count(*)::int AS total FROM listed' . $where,
            $params
        );
        $filtered = RowValue::integer($counted[0]['total'] ?? 0);
        $start = $this->start($query->window, $filtered);
        $rows = $this->statements->bound(
            self::LISTED . 'SELECT listed.id::text AS id, listed.name, listed.kind, listed.roles FROM listed'
                . $where . ' ORDER BY ' . $this->order($query) . $this->limit($query->window, $start),
            $params
        );

        return new PartyPage($this->listed($rows), $this->total(), $filtered, $start);
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function where(PartyPageQuery $query, bool $consignmentOnly): array
    {
        $parts = [];
        $params = [];
        foreach ($query->columns as $name => $needle) {
            [$match, $bound] = $this->match($name, $needle, $consignmentOnly);
            $parts[] = $match;
            $params = [...$params, ...$bound];
        }
        if ($query->search !== '') {
            $any = [];
            foreach (PartyPageQuery::SORTS as $name) {
                [$match, $bound] = $this->match($name, $query->search, $consignmentOnly);
                $any[] = $match;
                $params = [...$params, ...$bound];
            }
            $parts[] = '(' . implode(' OR ', $any) . ')';
        }
        if ($parts === []) {
            return ['', []];
        }

        return [' WHERE ' . implode(' AND ', $parts), $params];
    }

    /**
     * Roles are matched by the words staff see ("consignor", "wait list"), not the stored names.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function match(string $name, string $needle, bool $consignmentOnly): array
    {
        if ($name === 'name') {
            return ['strpos(lower(listed.name), ?) > 0', [$needle]];
        }
        if ($name === 'kind') {
            return ['strpos(listed.kind, ?) > 0', [$needle]];
        }
        $types = [];
        foreach ([PartyRoleType::VENDOR, PartyRoleType::CUSTOMER, PartyRoleType::WAIT_LIST] as $type) {
            if (str_contains(PartyRoleType::displayName($type, $consignmentOnly), $needle)) {
                $types[] = $type;
            }
        }
        if ($types === []) {
            return ['false', []];
        }

        return [
            "EXISTS (SELECT 1 FROM party_roles AS held WHERE held.party_id = listed.id AND held.status = 'active'"
                . ' AND held.role_type IN (' . implode(', ', array_fill(0, count($types), '?')) . '))',
            $types,
        ];
    }

    private function order(PartyPageQuery $query): string
    {
        $way = $query->direction === 'desc' ? 'DESC' : 'ASC';

        return sprintf(self::ORDER[$query->sort], $way);
    }

    private function start(ItemPageWindow $window, int $filtered): int
    {
        if ($window->mode === ItemPageWindow::ALL) {
            return 0;
        }
        if ($filtered === 0 || $window->start < $filtered) {
            return $window->start;
        }

        return intdiv($filtered - 1, $window->length) * $window->length;
    }

    private function limit(ItemPageWindow $window, int $start): string
    {
        if ($window->mode === ItemPageWindow::ALL) {
            return '';
        }

        return ' LIMIT ' . $window->length . ' OFFSET ' . $start;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: string, name: string, kind: string, roles: list<string>}>
     */
    private function listed(array $rows): array
    {
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'name' => RowValue::string($row['name'] ?? null),
                'kind' => RowValue::string($row['kind'] ?? null),
                'roles' => PartyRoleType::fromAgg(RowValue::string($row['roles'] ?? null)),
            ];
        }

        return $list;
    }
}
