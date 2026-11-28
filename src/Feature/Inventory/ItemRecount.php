<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

/**
 * The count an edit asks for, with its reason and who asked. Applied inside the
 * edit's transaction, through the database.
 */
final class ItemRecount
{
    public function __construct(
        private readonly ItemCount $counts,
        private readonly string $reasonId,
        private readonly CountWho $who,
    ) {
    }

    public function apply(string $itemId, int $count): void
    {
        $this->counts->change($itemId, $count, $this->reasonId, $this->who);
    }
}
