<?php

declare(strict_types=1);

namespace PerfectConsign\Ledger;

use PerfectConsign\Shared\Http\Request;

final class PostedLines
{
    /**
     * @return array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}
     */
    public static function entry(string $accountId, int $debit, int $credit, ?string $type, ?string $ref): array
    {
        return [
            'account_id' => $accountId,
            'debit' => $debit,
            'credit' => $credit,
            'subledger_type' => $type,
            'subledger_ref' => $ref,
        ];
    }

    /**
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    public static function read(Request $request, int $slots, bool $withSubledger): array
    {
        $lines = [];
        for ($slot = 1; $slot <= $slots; $slot++) {
            $accountId = $request->field('line_account_' . $slot);
            $amount = $request->field('line_amount_' . $slot);
            if ($accountId === '' && $amount === '') {
                continue;
            }
            if ($accountId === '' || $amount === '') {
                throw new DatabaseRefusal('each line needs an account and an amount');
            }
            $minor = Money::parseMinor($amount);
            $side = $request->field('line_side_' . $slot);
            if ($side !== 'debit' && $side !== 'credit') {
                throw new DatabaseRefusal('each line is a debit or a credit');
            }
            $subledgerType = null;
            $subledgerRef = null;
            if ($withSubledger) {
                $subledgerType = $request->field('line_subledger_type_' . $slot);
                $subledgerRef = $request->field('line_subledger_ref_' . $slot);
                if ($subledgerType === '' && $subledgerRef === '') {
                    $subledgerType = null;
                    $subledgerRef = null;
                } elseif ($subledgerType !== 'vendor' && $subledgerType !== 'booth') {
                    throw new DatabaseRefusal('a subledger is a vendor or a booth');
                }
            }
            $lines[] = [
                'account_id' => $accountId,
                'debit' => $side === 'debit' ? $minor : 0,
                'credit' => $side === 'credit' ? $minor : 0,
                'subledger_type' => $subledgerType,
                'subledger_ref' => $subledgerRef,
            ];
        }
        if (count($lines) < 2) {
            throw new DatabaseRefusal('a journal needs at least two lines');
        }

        return $lines;
    }
}
