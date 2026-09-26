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
            $line = self::filledLine($request, $slot, $withSubledger);
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        if (count($lines) < 2) {
            throw new DatabaseRefusal('a journal needs at least two lines');
        }

        return $lines;
    }

    /**
     * @return array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}|null
     */
    private static function filledLine(Request $request, int $slot, bool $withSubledger): ?array
    {
        $accountId = $request->field('line_account_' . $slot);
        $amount = $request->field('line_amount_' . $slot);
        if ($accountId === '' && $amount === '') {
            return null;
        }
        if ($accountId === '' || $amount === '') {
            throw new DatabaseRefusal('each line needs an account and an amount');
        }
        $minor = Money::parseMinor($amount);
        $side = self::side($request, $slot);
        $subledger = self::subledger($request, $slot, $withSubledger);

        return [
            'account_id' => $accountId,
            'debit' => $side === 'debit' ? $minor : 0,
            'credit' => $side === 'credit' ? $minor : 0,
            'subledger_type' => $subledger['type'],
            'subledger_ref' => $subledger['ref'],
        ];
    }

    private static function side(Request $request, int $slot): string
    {
        $side = $request->field('line_side_' . $slot);
        if ($side !== 'debit' && $side !== 'credit') {
            throw new DatabaseRefusal('each line is a debit or a credit');
        }

        return $side;
    }

    /**
     * @return array{type: ?string, ref: ?string}
     */
    private static function subledger(Request $request, int $slot, bool $withSubledger): array
    {
        if (!$withSubledger) {
            return ['type' => null, 'ref' => null];
        }
        $type = $request->field('line_subledger_type_' . $slot);
        $ref = $request->field('line_subledger_ref_' . $slot);
        if ($type === '' && $ref === '') {
            return ['type' => null, 'ref' => null];
        }
        if ($type !== 'vendor' && $type !== 'booth') {
            throw new DatabaseRefusal('a subledger is a vendor or a booth');
        }

        return ['type' => $type, 'ref' => $ref];
    }
}
