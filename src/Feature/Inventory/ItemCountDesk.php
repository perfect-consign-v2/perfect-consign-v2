<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Bootstrap\Actor;

/**
 * The count parts of an item screen: the "Why are there fewer?" question, the
 * count history, and the note for a piece whose vendor has left.
 */
final class ItemCountDesk
{
    public function __construct(
        private readonly ItemCount $counts,
        private readonly Actor $actor,
        private readonly DepartedPiece $departed,
    ) {
    }

    /**
     * @param array<string, mixed>|null $stored
     */
    public function asks(?array $stored, string $postedCount, string $reasonId): bool
    {
        return CountQuestion::needed($stored, $postedCount, $reasonId);
    }

    public function recount(string $reasonId): ItemRecount
    {
        return new ItemRecount($this->counts, $reasonId, CountWho::of($this->actor));
    }

    /**
     * @param array<string, mixed>|null $stored
     * @return array{countWas: string, countReasons: list<array{id: string, code: string, label: string}>, countHistory: list<string>, departed: array{vendor_id: string, vendor_name: string, held_on: string}|null}
     */
    public function view(string $itemId, ?array $stored, bool $asking): array
    {
        return [
            'countWas' => $asking ? CountQuestion::was($stored) : '',
            'countReasons' => $asking ? $this->counts->reasons(CountWho::of($this->actor)) : [],
            'countHistory' => $itemId === '' ? [] : $this->counts->history($itemId),
            'departed' => $itemId === '' ? null : $this->departed->of($itemId),
        ];
    }
}
