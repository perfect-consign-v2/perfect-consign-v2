<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\ActorRole;
use PerfectConsign\Ledger\DatabaseRefusal;

/**
 * Who changed an item's count: a vendor in their portal, or staff by name.
 * The database checks a vendor may use the reason and owns the item.
 */
final class CountWho
{
    private function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly ?string $vendorId,
    ) {
    }

    public static function of(Actor $actor): self
    {
        $role = $actor->role();
        $name = $role === ActorRole::OWNER ? $actor->username() : $actor->name();
        if ($name === '' || $role === '') {
            throw new DatabaseRefusal('the signed-in person is missing');
        }
        if ($role === ActorRole::VENDOR) {
            $vendorId = $actor->vendor();
            if ($vendorId === '') {
                throw new DatabaseRefusal('the signed-in person is missing');
            }

            return new self('vendor', $name, $vendorId);
        }

        return new self('staff', $name, null);
    }
}
