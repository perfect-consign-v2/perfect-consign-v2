<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Activity;

final class ActivityPhrase
{
    /** @var array<string, string> */
    private const PHRASES = [
        'adjustment.post' => 'Posted an adjustment',
        'assignment.create' => 'Started a booth stay',
        'assignment.end' => 'Ended a booth stay',
        'assignment.cancel' => 'Cancelled a booth reservation',
        'assignment.rent' => 'Changed the rent for a stay',
        'booth.create' => 'Added a booth',
        'booth.edit' => 'Changed a booth',
        'booth.correct' => 'Corrected a booth on a sale',
        'booth_charge' => 'Charged a booth extra',
        'booth_deposit' => 'Recorded a booth deposit or its return',
        'check.return' => 'Recorded a bounced check',
        'customer.create' => 'Added a customer',
        'customer.edit' => 'Changed a customer',
        'export.records' => 'Downloaded records',
        'floor.note' => 'Wrote a floor note',
        'item.count' => 'Changed an item count',
        'item.create' => 'Added an item',
        'item.delete' => 'Deleted an item',
        'item.edit' => 'Changed an item',
        'item.hold' => 'Held a piece for a vendor who left',
        'item.keep' => 'Kept a piece left behind',
        'item_buy.expire' => 'Bought an expired item',
        'mall_sale.create' => 'Recorded a mall sale',
        'markdown-floor' => 'Confirmed a markdown below the floor',
        'missed_sale.record' => 'Recorded a missed sale',
        'offer.create' => 'Started a deal',
        'offer.end' => 'Ended a deal',
        'opening.post' => 'Posted opening amounts',
        'owner.create' => 'Added an owner',
        'owner.password' => 'Changed an owner password',
        'owner.remove' => 'Removed an owner',
        'owner.restore' => 'Restored an owner',
        'owner.suspend' => 'Suspended an owner',
        'password.change' => 'Changed a password',
        'payout.close' => 'Closed a payout',
        'payout.disburse' => 'Paid a payout',
        'payout.settle' => 'Settled a payout',
        'payout.undo' => 'Undid a payout',
        'pickup.record' => 'Recorded a pickup',
        'quantity_reason.create' => 'Added a count reason',
        'quantity_reason.edit' => 'Changed a count reason',
        'platform.enter' => 'Entered the store',
        'register.close' => 'Closed a register',
        'register.drop' => 'Dropped cash from a register',
        'register.open' => 'Opened a register',
        'rent.rate' => "Changed a booth's rent",
        'rent.waive' => 'Waived rent',
        'sale.discount' => 'Discounted a sale',
        'sale.pay' => 'Paid a sale',
        'sale.price' => 'Changed a price on a sale',
        'sale.return' => 'Returned a sale',
        'sale.return_tender' => 'Changed a return tender',
        'sale.tax_free' => 'Waived tax on a sale',
        'sale.void' => 'Voided a sale',
        'settings.edit' => 'Saved company settings',
        'settings.mall' => 'Saved money or rent settings',
        'staff.create' => 'Added a staff login',
        'staff.password' => 'Changed a staff password',
        'staff.restore' => 'Restored a staff login',
        'staff.role' => 'Changed a staff role',
        'staff.suspend' => 'Suspended a staff login',
        'store_use.record' => 'Recorded a store purchase',
        'tax.save' => 'Saved sales tax',
        'vendor.create' => 'Added a vendor',
        'vendor.edit' => 'Changed a vendor',
        'vendor.write_off' => 'Wrote off what a vendor owes',
        'vendor.write_off_recover' => 'Took back money written off',
        'vendor_memo.record' => 'Recorded a vendor memo',
        'year.close' => 'Closed the year',
    ];

    public static function has(string $action): bool
    {
        return isset(self::PHRASES[$action]);
    }

    public static function text(string $action, string $seller = 'vendor'): string
    {
        $phrase = self::PHRASES[$action] ?? $action;
        if ($seller !== 'consignor') {
            return $phrase;
        }

        return str_replace(['Vendor', 'vendor'], ['Consignor', 'consignor'], $phrase);
    }

    /**
     * @return list<array{code: string, phrase: string}>
     */
    public static function choices(string $seller = 'vendor'): array
    {
        $choices = [];
        foreach (array_keys(self::PHRASES) as $code) {
            $choices[] = ['code' => $code, 'phrase' => self::text($code, $seller)];
        }
        usort($choices, self::order(...));

        return $choices;
    }

    /**
     * @param array{code: string, phrase: string} $left
     * @param array{code: string, phrase: string} $right
     */
    public static function order(array $left, array $right): int
    {
        $byPhrase = strcasecmp($left['phrase'], $right['phrase']);
        if ($byPhrase !== 0) {
            return $byPhrase;
        }

        return strcasecmp($left['code'], $right['code']);
    }
}
