<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\CashRound;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;

final class SaleJournal
{
    /**
     * @param list<array{vendor_id: string, price: int, quantity: int, commission: int, tax: int, discount: int, store_discount: int}> $items
     * @param list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}> $tenders
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    public static function sale(LedgerStore $ledger, array $items, array $tenders, int $cash, int $roundOn, int $due): array
    {
        $delta = $roundOn >= 1 && self::onlyCash($tenders, $cash) ? CashRound::adjustment($due, $roundOn) : 0;
        if ($delta === 0 || $cash === $due) {
            $lines = $delta === 0 ? $tenders : self::roundedCash($ledger, $tenders, $cash, $delta);
        } else {
            $lines = self::keptCash($ledger, $tenders, $delta);
        }
        $promo = 0;
        foreach ($items as $item) {
            $promo += $item['store_discount'];
        }
        if ($promo > 0) {
            $lines[] = PostedLines::entry($ledger->roleAccount('promotion_expense'), $promo, 0, null, null);
        }

        return array_merge($lines, self::credits($ledger, $items));
    }

    /**
     * @param array{price: int, commission: int, tax: int, discount: int, store_discount: int, placement?: string, cost?: int} $item
     * @param array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string} $credit
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    public static function giveBack(
        LedgerStore $ledger,
        array $item,
        string $vendorId,
        array $credit,
        int $openPayable = 0,
    ): array {
        $price = $item['price'];
        $commission = $item['commission'];
        $tax = $item['tax'];
        $discount = $item['discount'];
        $storeDiscount = $item['store_discount'];
        $placement = $item['placement'] ?? 'consigned';
        $cost = $item['cost'] ?? 0;
        $lines = [];
        if ($placement === 'store') {
            $lines = self::storeReturn($ledger, $price, $discount, $cost);
        } elseif ($commission > 0) {
            $lines[] = PostedLines::entry($ledger->roleAccount('commission_revenue'), $commission, 0, null, null);
        }
        if ($tax > 0) {
            $lines[] = PostedLines::entry($ledger->roleAccount('sales_tax_payable'), $tax, 0, null, null);
        }
        if ($storeDiscount > 0) {
            $lines[] = PostedLines::entry($ledger->roleAccount('promotion_expense'), 0, $storeDiscount, null, null);
        }
        $owed = $price - $discount - $commission;
        if ($placement !== 'store' && $owed > 0) {
            $againstPayable = min($owed, max(0, $openPayable));
            $clawback = $owed - $againstPayable;
            if ($againstPayable > 0) {
                $lines[] = PostedLines::entry($ledger->roleAccount('vendor_payable'), $againstPayable, 0, 'vendor', $vendorId);
            }
            if ($clawback > 0) {
                $lines[] = PostedLines::entry($ledger->roleAccount('vendor_receivable'), $clawback, 0, 'vendor', $vendorId);
            }
        }
        $lines[] = $credit;

        return $lines;
    }

    /**
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    private static function storeReturn(LedgerStore $ledger, int $price, int $discount, int $cost): array
    {
        $lines = [];
        $net = $price - $discount;
        if ($net > 0) {
            $lines[] = PostedLines::entry($ledger->roleAccount('store_sales'), $net, 0, null, null);
        }
        if ($cost > 0) {
            $lines[] = PostedLines::entry($ledger->roleAccount('inventory'), $cost, 0, null, null);
            $lines[] = PostedLines::entry($ledger->roleAccount('cost_of_goods'), 0, $cost, null, null);
        }

        return $lines;
    }

    /**
     * @param list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}> $tenders
     */
    private static function onlyCash(array $tenders, int $cash): bool
    {
        $sum = 0;
        foreach ($tenders as $tender) {
            $sum += $tender['debit'];
        }

        return $cash > 0 && $sum === $cash;
    }

    /**
     * @param list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}> $tenders
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    private static function roundedCash(LedgerStore $ledger, array $tenders, int $cash, int $delta): array
    {
        $cashAccount = $ledger->roleAccount('cash');
        $lines = [];
        foreach ($tenders as $tender) {
            if ($tender['account_id'] === $cashAccount && $tender['debit'] === $cash) {
                $tender['debit'] = $cash + $delta;
            }
            $lines[] = $tender;
        }
        $account = $ledger->roleAccount('cash_rounding');
        if ($delta > 0) {
            $lines[] = PostedLines::entry($account, 0, $delta, null, null);
        } else {
            $lines[] = PostedLines::entry($account, -$delta, 0, null, null);
        }

        return $lines;
    }

    /**
     * @param list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}> $tenders
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    private static function keptCash(LedgerStore $ledger, array $tenders, int $delta): array
    {
        $lines = $tenders;
        $account = $ledger->roleAccount('cash_rounding');
        if ($delta > 0) {
            $lines[] = PostedLines::entry($account, 0, $delta, null, null);
        } else {
            $lines[] = PostedLines::entry($account, -$delta, 0, null, null);
        }

        return $lines;
    }

    /**
     * @param list<array{vendor_id: string, price: int, quantity: int, commission: int, tax: int, discount: int, store_discount: int}> $items
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    private static function credits(LedgerStore $ledger, array $items): array
    {
        $commission = 0;
        $tax = 0;
        $storeSales = 0;
        $cogs = 0;
        $payable = [];
        foreach ($items as $item) {
            $tax += $item['tax'];
            if (($item['placement'] ?? 'consigned') === 'store') {
                [$storeSales, $cogs] = self::storeCredit($item, $storeSales, $cogs);
                continue;
            }
            $commission += $item['commission'];
            $owed = $item['price'] * $item['quantity'] - $item['discount'] - $item['commission'];
            if ($owed > 0) {
                $vendor = $item['vendor_id'];
                $payable[$vendor] = ($payable[$vendor] ?? 0) + $owed;
            }
        }
        return self::creditLines($ledger, $commission, $storeSales, $cogs, $tax, $payable);
    }

    /**
     * @param array<string, int> $payable
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    private static function creditLines(
        LedgerStore $ledger,
        int $commission,
        int $storeSales,
        int $cogs,
        int $tax,
        array $payable,
    ): array {
        $lines = [];
        if ($commission > 0) {
            $lines[] = PostedLines::entry($ledger->roleAccount('commission_revenue'), 0, $commission, null, null);
        }
        if ($storeSales > 0) {
            $lines[] = PostedLines::entry($ledger->roleAccount('store_sales'), 0, $storeSales, null, null);
        }
        if ($cogs > 0) {
            $lines[] = PostedLines::entry($ledger->roleAccount('cost_of_goods'), $cogs, 0, null, null);
            $lines[] = PostedLines::entry($ledger->roleAccount('inventory'), 0, $cogs, null, null);
        }
        if ($tax > 0) {
            $lines[] = PostedLines::entry($ledger->roleAccount('sales_tax_payable'), 0, $tax, null, null);
        }
        foreach ($payable as $vendor => $amount) {
            $lines[] = PostedLines::entry($ledger->roleAccount('vendor_payable'), 0, $amount, 'vendor', $vendor);
        }

        return $lines;
    }

    /**
     * @param array{price: int, quantity: int, discount: int, cost?: int} $item
     * @return array{0: int, 1: int}
     */
    private static function storeCredit(array $item, int $storeSales, int $cogs): array
    {
        $storeSales += $item['price'] * $item['quantity'] - $item['discount'];
        $cost = $item['cost'] ?? 0;
        if ($cost > 0) {
            $cogs += $cost * $item['quantity'];
        }

        return [$storeSales, $cogs];
    }
}
