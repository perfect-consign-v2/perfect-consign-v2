<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Activity;

use PerfectConsign\Bootstrap\RoleGate;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class ActivityHref
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @param list<array{
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
     * }> $rows
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
     *     recorded: bool,
     *     href: string
     * }>
     */
    public function apply(string $slug, string $role, string $sellers, array $rows): array
    {
        $this->sellers($sellers);
        $receipts = $this->receipts($rows);
        $linked = [];
        foreach ($rows as $row) {
            $row['href'] = $this->href($slug, $role, $sellers, $row, $receipts);
            $linked[] = $row;
        }

        return $linked;
    }

    private function sellers(string $sellers): void
    {
        if ($sellers !== 'vendors' && $sellers !== 'consignors') {
            throw new \InvalidArgumentException('the seller path is missing');
        }
    }

    /**
     * @param list<array{entity_type: string, entity_id: string}> $rows
     * @return array<string, string>
     */
    private function receipts(array $rows): array
    {
        $ids = $this->ticketIds($rows);
        if ($ids === []) {
            return [];
        }

        return $this->singleJournals($this->journalRows($ids));
    }

    /**
     * @param list<array{entity_type: string, entity_id: string}> $rows
     * @return list<string>
     */
    private function ticketIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($row['entity_type'] === 'ticket' && self::uuid($row['entity_id'])) {
                $ids[$row['entity_id']] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    private function journalRows(array $ids): array
    {
        $marks = [];
        $params = [];
        foreach ($ids as $id) {
            $marks[] = '?::uuid';
            $params[] = $id;
        }

        return $this->statements->bound(
            'SELECT ticket.id::text AS ticket_id, journal.id::text AS journal_id
             FROM tickets AS ticket
             JOIN journals AS journal ON journal.posting_key = ticket.posting_key
             WHERE ticket.id IN (' . implode(', ', $marks) . ')',
            $params,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, string>
     */
    private function singleJournals(array $rows): array
    {
        $found = [];
        $counts = [];
        foreach ($rows as $row) {
            $ticket = self::text($row['ticket_id'] ?? null);
            $journal = self::text($row['journal_id'] ?? null);
            if (!self::uuid($ticket) || !self::uuid($journal)) {
                continue;
            }
            $counts[$ticket] = ($counts[$ticket] ?? 0) + 1;
            $found[$ticket] = $journal;
        }
        $single = [];
        foreach ($found as $ticket => $journal) {
            if (($counts[$ticket] ?? 0) === 1) {
                $single[$ticket] = $journal;
            }
        }

        return $single;
    }

    /**
     * @param array{id: string, action: string, entity_type: string, entity_id: string} $row
     * @param array<string, string> $receipts
     */
    private function href(string $slug, string $role, string $sellers, array $row, array $receipts): string
    {
        $rest = $this->rest($sellers, $row, $receipts);
        if ($rest === null || !RoleGate::allows($role, $rest, 'GET')) {
            return '';
        }

        return '/' . $slug . $rest;
    }

    /**
     * @param array{action: string, entity_type: string, entity_id: string} $row
     * @param array<string, string> $receipts
     */
    private function rest(string $sellers, array $row, array $receipts): ?string
    {
        $id = $row['entity_id'];
        if (!self::uuid($id)) {
            return null;
        }
        if ($row['entity_type'] === 'ticket') {
            $journal = $receipts[$id] ?? '';
            if ($journal === '') {
                return null;
            }

            return '/pos/receipt/' . $journal;
        }
        if ($row['entity_type'] === 'item' && $row['action'] === 'item.delete') {
            return null;
        }

        return match ($row['entity_type']) {
            'journal' => '/journals/' . $id,
            'item' => '/inventory/' . $id . '/edit',
            'booth' => '/booths/' . $id . '/edit',
            'vendor' => '/' . $sellers . '/' . $id,
            'customer' => '/customers/' . $id,
            'offer' => '/offers/' . $id,
            default => null,
        };
    }

    private static function uuid(string $value): bool
    {
        return preg_match('/^[0-9a-fA-F-]{36}$/', $value) === 1;
    }

    private static function text(mixed $value): string
    {
        return RowValue::optionalString($value) ?? '';
    }
}
