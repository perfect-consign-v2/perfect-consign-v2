<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

/**
 * Verb list for the four seeded roles. Path checks stay in RoleGate and must agree with this list.
 */
final class Permission
{
    /**
     * @var list<string>
     */
    public const VERBS = [
        'sale.post',
        'sale.void',
        'sale.discount',
        'sale.tax_free',
        'payout.disburse',
        'rent.waive',
        'register.close',
        'settings.edit',
        'report.read',
    ];

    public static function allows(string $role, string $verb): bool
    {
        if ($role === 'owner') {
            return true;
        }
        if ($role === 'cashier') {
            return in_array($verb, ['sale.post', 'sale.void', 'sale.discount', 'sale.tax_free', 'register.close'], true);
        }
        if ($role === 'books') {
            return $verb === 'report.read';
        }

        return false;
    }
}
