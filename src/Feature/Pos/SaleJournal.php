<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\CashRound;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;

final class SaleJournal
{
    /**
     * @param list<array{vendor_id: string, price: int, quantity: int, commission: int, tax: int, discount: int}> $pieces
     * @param list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}> $tenders
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    public static function sale(LedgerStore $ledger, array $pieces, array $tenders, int $cash, int $roundOn): array
    {
        $delta = $roundOn === 1 && $cash > 0 ? CashRound::adjustment($cash) : 0;
        $lines = $delta === 0 ? $tenders : self::roundedCash($ledger, $tenders, $cash, $delta);

        return array_merge($lines, self::credits($ledger, $pieces));
    }

    /**
     * @param array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string} $credit
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    public static function giveBack(
        LedgerStore $ledger,
        int $price,
        int $commission,
        int $tax,
        int $discount,
        string $vendorId,
        array $credit,
    ): array {
        $lines = [];
        if ($commission > 0) {
            $lines[] = PostedLines::entry($ledger->roleAccount('commission_revenue'), $commission, 0, null, null);
        }
        if ($tax > 0) {
            $lines[] = PostedLines::entry($ledger->roleAccount('sales_tax_payable'), $tax, 0, null, null);
        }
        $owed = $price - $discount - $commission;
        if ($owed > 0) {
            $lines[] = PostedLines::entry($ledger->roleAccount('vendor_payable'), $owed, 0, 'vendor', $vendorId);
        }
        $lines[] = $credit;

        return $lines;
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
     * @param list<array{vendor_id: string, price: int, quantity: int, commission: int, tax: int, discount: int}> $pieces
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    private static function credits(LedgerStore $ledger, array $pieces): array
    {
        $commission = 0;
        $tax = 0;
        $payable = [];
        foreach ($pieces as $piece) {
            $commission += $piece['commission'];
            $tax += $piece['tax'];
            $owed = $piece['price'] * $piece['quantity'] - $piece['discount'] - $piece['commission'];
            if ($owed > 0) {
                $vendor = $piece['vendor_id'];
                $payable[$vendor] = ($payable[$vendor] ?? 0) + $owed;
            }
        }
        $lines = [];
        if ($commission > 0) {
            $lines[] = PostedLines::entry($ledger->roleAccount('commission_revenue'), 0, $commission, null, null);
        }
        if ($tax > 0) {
            $lines[] = PostedLines::entry($ledger->roleAccount('sales_tax_payable'), 0, $tax, null, null);
        }
        foreach ($payable as $vendor => $amount) {
            $lines[] = PostedLines::entry($ledger->roleAccount('vendor_payable'), 0, $amount, 'vendor', $vendor);
        }

        return $lines;
    }
}
