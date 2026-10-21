<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use InvalidArgumentException;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class StoreUse
{
    public function __construct(
        private readonly StorePurchase $purchases,
        private readonly Statements $statements,
    ) {
    }

    public function record(string $vendorId, string $itemCode, int $amount, string $postingDate, string $description, string $memo): string
    {
        $trimmedDescription = trim($description);
        $trimmedMemo = trim($memo);
        if ($trimmedDescription === '' || $trimmedMemo === '') {
            throw new InvalidArgumentException('a store use names the piece');
        }
        $itemId = $this->itemId($vendorId, $itemCode);
        $reference = bin2hex(random_bytes(16));

        return $this->purchases->record(
            $vendorId,
            $postingDate,
            $amount,
            $reference,
            function (string $journalId) use ($vendorId, $itemId, $amount, $trimmedDescription, $trimmedMemo): void {
                $this->statements->change(
                    'INSERT INTO store_uses (journal_id, vendor_id, item_id, amount_minor, description, memo)
                     VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?, ?)',
                    [$journalId, $vendorId, $itemId, $amount, $trimmedDescription, $trimmedMemo]
                );
            },
        );
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function vendors(): array
    {
        $rows = $this->statements->rows(
            'SELECT id::text AS id, name FROM vendors WHERE active = true ORDER BY name, code'
        );
        $vendors = [];
        foreach ($rows as $row) {
            $vendors[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'name' => RowValue::string($row['name'] ?? null),
            ];
        }

        return $vendors;
    }

    private function itemId(string $vendorId, string $itemCode): ?string
    {
        $code = trim($itemCode);
        if ($code === '') {
            return null;
        }
        $rows = $this->statements->bound(
            'SELECT id::text AS id FROM items WHERE vendor_id = ?::uuid AND lower(code) = lower(?)',
            [$vendorId, $code]
        );
        if (count($rows) !== 1) {
            throw new DatabaseRefusal('a store use needs an item');
        }

        return RowValue::string($rows[0]['id'] ?? null);
    }
}
