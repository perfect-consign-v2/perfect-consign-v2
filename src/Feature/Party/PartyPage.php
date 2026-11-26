<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

/** One page of parties plus the two DataTables counts. */
final class PartyPage
{
    /**
     * @param list<array{id: string, name: string, kind: string, roles: list<string>}> $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $total,
        public readonly int $filtered,
        public readonly int $start,
    ) {
    }
}
