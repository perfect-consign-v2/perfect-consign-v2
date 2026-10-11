<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Scalar\RowValue;

final class ReceiptChange
{
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
     * @param array{lines: list<array{item_id: string, price: int, discount: int, tax: int}>} $receipt
     */
    public function owed(array $receipt, string $itemId): int
    {
        foreach ($receipt['lines'] as $line) {
            if ($line['item_id'] === $itemId) {
                return RowValue::integer($line['price']) - RowValue::integer($line['discount']) + RowValue::integer($line['tax']);
            }
        }

        throw new DatabaseRefusal('that item is not on the ticket');
    }

    private function amount(string $value): bool
    {
        if ($value === '0' || $value === '0.0' || $value === '0.00') {
            return false;
        }

        return preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value) === 1;
    }
}
