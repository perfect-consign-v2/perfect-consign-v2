<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\CashRound;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Scalar\RowValue;

final class ReceiptChange
{
    public function accept(int $due, int $sum, int $cash, int $roundOn, ?int $handed): void
    {
        $this->settled($due, $sum, $cash, $roundOn);
        $this->handed($cash, $roundOn, $handed);
    }

    public function due(string $tendered, int $cash): ?int
    {
        if ($tendered === '' || $cash < 1 || !$this->amount($tendered)) {
            return null;
        }
        $handed = Money::parseMinor($tendered);
        if ($handed < $cash) {
            return null;
        }

        return $handed - $cash;
    }

    /**
     * @param array{lines: list<array{item_id: string, price: int, quantity?: int, discount: int, store_discount?: int, tax: int}>} $receipt
     */
    public function owed(array $receipt, string $itemId): int
    {
        foreach ($receipt['lines'] as $line) {
            if ($line['item_id'] === $itemId) {
                $quantity = RowValue::integer($line['quantity'] ?? 1);
                $count = $quantity > 0 ? $quantity : 1;
                $store = RowValue::integer($line['store_discount'] ?? 0);

                return RowValue::integer($line['price']) * $count
                    - RowValue::integer($line['discount'])
                    - $store
                    + RowValue::integer($line['tax']);
            }
        }

        throw new DatabaseRefusal('that item is not on the ticket');
    }

    private function settled(int $due, int $sum, int $cash, int $roundOn): void
    {
        $cashOnly = $cash > 0 && $cash === $sum;
        $settled = $roundOn >= 1 && $cashOnly ? CashRound::rounded($due, $roundOn) : $due;
        if ($sum !== $due && $sum !== $settled) {
            throw new DatabaseRefusal('the tenders must add up to the amount due');
        }
    }

    private function handed(int $cash, int $roundOn, ?int $handed): void
    {
        if ($handed === null || $cash < 1) {
            return;
        }
        $cashDue = $roundOn >= 1 ? $cash + CashRound::adjustment($cash, $roundOn) : $cash;
        if ($handed < $cashDue) {
            throw new DatabaseRefusal('the cash received is less than the cash due');
        }
    }

    private function amount(string $value): bool
    {
        if ($value === '0' || $value === '0.0' || $value === '0.00') {
            return false;
        }

        return preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value) === 1;
    }
}
