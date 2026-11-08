<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

final class PartyRoleType
{
    public const VENDOR = 'vendor';

    public const CUSTOMER = 'customer';

    public const WAIT_LIST = 'wait_list';

    /** Staff-facing label for a stored role_type. Seller stays vendor in the DB. */
    public static function displayName(string $roleType, bool $consignmentOnly): string
    {
        return match ($roleType) {
            self::VENDOR => $consignmentOnly ? 'consignor' : 'vendor',
            self::CUSTOMER => 'customer',
            self::WAIT_LIST => 'wait list',
            default => $roleType,
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

    /** Map a string_agg of role_type values for the parties list. */
    public static function displayCsv(string $roleCsv, bool $consignmentOnly): string
    {
        if ($roleCsv === '') {
            return '';
        }
        $labels = [];
        foreach (explode(', ', $roleCsv) as $roleType) {
            if ($roleType === '') {
                continue;
            }
            $labels[] = self::displayName($roleType, $consignmentOnly);
        }

        return implode(', ', $labels);
    }
}
