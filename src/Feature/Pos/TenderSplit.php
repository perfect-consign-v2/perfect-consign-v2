<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Register\RegisterStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Http\Request;

final class TenderSplit
{
    /** @var list<string> */
    private const ROLES = ['cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit', 'vendor_draw'];

    /** @var array<string, string> */
    private const STORED = [
        'gift_certificate' => 'gift_certificate',
        'gift_card' => 'gift_card',
        'store_credit' => 'store_credit',
    ];

    public function __construct(
        private readonly LedgerStore $ledger,
        private readonly RegisterStore $registers,
        private readonly PayoutStore $payouts,
    ) {
    }

    /**
     * @return array{
     *     lines: list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>,
     *     sum: int,
     *     check: ?string,
     *     cash: int
     * }
     */
    public function read(Request $request): array
    {
        $lines = [];
        $sum = 0;
        $cash = 0;
        $check = $this->checkNumber($request->field('check_number'));
        $checkUsed = false;
        foreach (self::ROLES as $role) {
            $minor = $this->minor($request->field('tender_' . $role));
            if ($minor === 0) {
                continue;
            }
            if ($role === 'cash') {
                $cash = $minor;
            }
            if ($role === 'checks') {
                $checkUsed = true;
                if ($check === null) {
                    throw new DatabaseRefusal('a check sale needs a check number');
                }
            }
            $lines[] = $this->debit($request, $role, $minor);
            $sum += $minor;
        }
        if ($lines === []) {
            throw new DatabaseRefusal('a sale debits each tender once');
        }

        return [
            'lines' => $lines,
            'sum' => $sum,
            'check' => $checkUsed ? $check : null,
            'cash' => $cash,
        ];
    }

    /**
     * @return array{
     *     line: array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string},
     *     check: ?string
     * }
     */
    public function credit(Request $request, int $amount): array
    {
        $role = $request->field('return_tender');
        if (!in_array($role, self::ROLES, true)) {
            throw new DatabaseRefusal('a return credits one tender');
        }
        $check = null;
        if ($role === 'checks') {
            $check = $this->checkNumber($request->field('return_check_number'));
            if ($check === null) {
                throw new DatabaseRefusal('a check sale needs a check number');
            }
        }
        $subledger = $this->subledger($request, $role, 'return_number');

        return [
            'line' => PostedLines::entry(
                $this->ledger->roleAccount($role),
                0,
                $amount,
                $subledger['type'],
                $subledger['ref']
            ),
            'check' => $check,
        ];
    }

    private function minor(string $value): int
    {
        if ($value === '' || $value === '0' || $value === '0.00') {
            return 0;
        }

        return Money::parseMinor($value);
    }

    private function checkNumber(string $number): ?string
    {
        if ($number === '') {
            return null;
        }
        if (strlen($number) > 40) {
            throw new DatabaseRefusal('a check sale needs a check number');
        }

        return $number;
    }

    /**
     * @return array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}
     */
    private function debit(Request $request, string $role, int $amount): array
    {
        if ($role === 'vendor_draw') {
            $vendor = $request->field('vendor_id');
            if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendor) !== 1) {
                throw new DatabaseRefusal('Choose a vendor.');
            }
            if ($amount > $this->payouts->openPayout($vendor)['payable']) {
                throw new DatabaseRefusal('a vendor draw is limited to the open payable');
            }

            return PostedLines::entry($this->ledger->roleAccount('vendor_payable'), $amount, 0, 'vendor', $vendor);
        }
        $subledger = $this->subledger($request, $role, $role . '_number');

        return PostedLines::entry(
            $this->ledger->roleAccount($role),
            $amount,
            0,
            $subledger['type'],
            $subledger['ref']
        );
    }

    /**
     * @return array{type: ?string, ref: ?string}
     */
    private function subledger(Request $request, string $role, string $numberField): array
    {
        if (!isset(self::STORED[$role])) {
            return ['type' => null, 'ref' => null];
        }
        $kind = self::STORED[$role];

        return [
            'type' => $kind,
            'ref' => $this->registers->storedValueId($kind, $request->field($numberField)),
        ];
    }
}
