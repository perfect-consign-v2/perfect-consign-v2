<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Book;

use InvalidArgumentException;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class MissedSale
{
    public function __construct(
        private readonly LedgerStore $ledger,
        private readonly Statements $statements,
    ) {
    }

    public function vendorShare(string $vendorId, string $itemCode, int $price): int
    {
        $rate = $this->rate($vendorId, $itemCode);

        return MissedSaleQuote::vendorShare($price, $rate['bps']);
    }

    public function record(string $vendorId, string $itemCode, int $price, string $postingDate, string $description, string $memo): string
    {
        $trimmedDescription = trim($description);
        $trimmedMemo = trim($memo);
        if ($trimmedDescription === '' || $trimmedMemo === '') {
            throw new InvalidArgumentException('a missed sale names the piece');
        }
        $rate = $this->rate($vendorId, $itemCode);
        $share = MissedSaleQuote::vendorShare($price, $rate['bps']);
        $reference = bin2hex(random_bytes(16));
        $itemId = $rate['item_id'];

        return $this->ledger->post(new JournalDraft(
            postingKey: 'missed-sale:' . $reference,
            postingDate: $postingDate,
            source: new JournalSource('missed_sale', $reference, 'Missed sale'),
            lines: [
                PostedLines::entry($this->ledger->roleAccount('operating_expense'), $share, 0, null, null),
                PostedLines::entry($this->ledger->roleAccount('vendor_payable'), 0, $share, 'vendor', $vendorId),
            ],
            beforeCommit: function (string $journalId) use ($vendorId, $itemId, $price, $trimmedDescription, $trimmedMemo): void {
                $this->statements->change(
                    'INSERT INTO missed_sales (journal_id, vendor_id, item_id, price_minor, description, memo)
                     VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?, ?)',
                    [$journalId, $vendorId, $itemId, $price, $trimmedDescription, $trimmedMemo]
                );
            },
        ));
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

    /**
     * @return array{bps: int, item_id: ?string}
     */
    private function rate(string $vendorId, string $itemCode): array
    {
        $code = trim($itemCode);
        if ($code === '') {
            $rows = $this->statements->bound(
                'SELECT COALESCE(vendor.commission_bps, mall.commission_bps) AS bps
                 FROM vendors AS vendor
                 CROSS JOIN mall
                 WHERE vendor.id = ?::uuid',
                [$vendorId]
            );
            if ($rows === []) {
                throw new DatabaseRefusal('a missed sale needs a vendor');
            }

            return ['bps' => RowValue::integer($rows[0]['bps'] ?? null), 'item_id' => null];
        }

        $rows = $this->statements->bound(
            'SELECT item.id::text AS item_id,
                    COALESCE(item.commission_bps, vendor.commission_bps, mall.commission_bps) AS bps
             FROM items AS item
             JOIN vendors AS vendor ON vendor.id = item.vendor_id
             CROSS JOIN mall
             WHERE item.vendor_id = ?::uuid AND lower(item.code) = lower(?)',
            [$vendorId, $code]
        );
        if ($rows === []) {
            throw new DatabaseRefusal('a missed sale needs an item');
        }

        return [
            'bps' => RowValue::integer($rows[0]['bps'] ?? null),
            'item_id' => RowValue::string($rows[0]['item_id'] ?? null),
        ];
    }
}
