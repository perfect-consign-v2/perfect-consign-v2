<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

/**
 * Seeded mall session roles. Path checks and verb lists must agree with this set.
 */
final class ActorRole
{
    public const OWNER = 'owner';
    public const CASHIER = 'cashier';
    public const MANAGER = 'manager';
    public const BOOKS = 'books';
    public const VENDOR = 'vendor';

    /** @var list<string> */
    public const ALL = [
        self::OWNER,
        self::CASHIER,
        self::MANAGER,
        self::BOOKS,
        self::VENDOR,
    ];

    public static function isKnown(string $role): bool
    {
        return in_array($role, self::ALL, true);
    }
}
