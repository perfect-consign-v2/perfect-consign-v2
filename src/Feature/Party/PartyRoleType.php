<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use InvalidArgumentException;

final class PartyRoleType
{
    public const VENDOR = 'vendor';

    public const CUSTOMER = 'customer';

    public const WAIT_LIST = 'wait_list';

    /**
     * @return self::VENDOR|self::CUSTOMER|self::WAIT_LIST
     */
    public static function assertStored(string $roleType): string
    {
        if ($roleType === self::VENDOR || $roleType === self::CUSTOMER || $roleType === self::WAIT_LIST) {
            return $roleType;
        }

        throw new InvalidArgumentException('that party role is not recognized');
    }

    /**
     * Decode a Postgres string_agg of role_type into stored role constants.
     *
     * @return list<string>
     */
    public static function fromAgg(string $roleAgg): array
    {
        if ($roleAgg === '') {
            return [];
        }
        $roles = [];
        foreach (explode(',', $roleAgg) as $part) {
            $roleType = trim($part);
            if ($roleType === '') {
                throw new InvalidArgumentException('that party role list is malformed');
            }
            $roles[] = self::assertStored($roleType);
        }

        return $roles;
    }

    /** Staff-facing label for a stored role_type. Seller stays vendor in the DB. */
    public static function displayName(string $roleType, bool $consignmentOnly): string
    {
        return match (self::assertStored($roleType)) {
            self::VENDOR => $consignmentOnly ? 'consignor' : 'vendor',
            self::CUSTOMER => 'customer',
            self::WAIT_LIST => 'wait list',
        };
    }

    /**
     * @param list<string> $roleTypes
     */
    public static function displayNames(array $roleTypes, bool $consignmentOnly): string
    {
        if ($roleTypes === []) {
            return '';
        }
        $labels = [];
        foreach ($roleTypes as $roleType) {
            $labels[] = self::displayName($roleType, $consignmentOnly);
        }

        return implode(', ', $labels);
    }
}
