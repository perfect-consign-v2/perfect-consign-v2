<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

use InvalidArgumentException;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class ExpireDesk
{
    private const string QUEUE = 'SELECT item.id::text AS id, item.code, item.description, item.price_minor,
                       item.quantity, item.expires_on::text AS expires_on,
                       item.vendor_id::text AS vendor_id,
                       vendor.code AS vendor_code, vendor.name AS vendor_name,
                       vendor.expiry_action
                FROM items AS item
                INNER JOIN vendors AS vendor ON vendor.id = item.vendor_id
                WHERE item.placement = \'consigned\'
                  AND item.expires_on IS NOT NULL
                  AND item.expires_on < (clock_timestamp() AT TIME ZONE (SELECT time_zone FROM mall))::date
                  AND item.quantity > 0
                  AND NOT EXISTS (SELECT 1 FROM item_dispositions WHERE item_id = item.id)
                  AND NOT EXISTS (SELECT 1 FROM item_buys WHERE item_id = item.id)
                ORDER BY item.expires_on, lower(item.code), item.code';

    public function __construct(
        private readonly Statements $statements,
        private readonly LedgerStore $ledger,
        private readonly JournalAudit $audit,
    ) {
    }

    /**
     * @return list<array{
     *     id: string,
     *     code: string,
     *     description: string,
     *     price_minor: int,
     *     quantity: int,
     *     expires_on: string,
     *     vendor_id: string,
     *     vendor_code: string,
     *     vendor_name: string,
     *     expiry_action: string
     * }>
     */
    public function queue(): array
    {
        $rows = $this->statements->rows(self::QUEUE);
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'code' => RowValue::string($row['code'] ?? null),
                'description' => RowValue::string($row['description'] ?? null),
                'price_minor' => RowValue::integer($row['price_minor'] ?? null),
                'quantity' => RowValue::integer($row['quantity'] ?? null),
                'expires_on' => RowValue::string($row['expires_on'] ?? null),
                'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
                'vendor_code' => RowValue::string($row['vendor_code'] ?? null),
                'vendor_name' => RowValue::string($row['vendor_name'] ?? null),
                'expiry_action' => $this->action(RowValue::string($row['expiry_action'] ?? null)),
            ];
        }

        return $list;
    }

    public function dispose(string $itemId, string $kind, string $postedOn): void
    {
        if (!in_array($kind, ['return', 'donate'], true)) {
            throw new InvalidArgumentException('Choose return or donate.');
        }
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $itemId) !== 1) {
            throw new InvalidArgumentException('Choose an item.');
        }
        $this->statements->change(
            'INSERT INTO item_dispositions (item_id, kind, posted_on) VALUES (?::uuid, ?, ?::date)',
            [$itemId, $kind, $postedOn]
        );
    }

    public function buy(string $itemId, string $amountText, string $postedOn, string $token): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $itemId) !== 1) {
            throw new InvalidArgumentException('Choose an item.');
        }
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            throw new InvalidArgumentException('this form has expired');
        }
        $amount = Money::parseMinor($amountText);
        if ($amount <= 0) {
            throw new InvalidArgumentException('a store purchase is a positive amount');
        }
        $item = $this->item($itemId);
        $vendorId = $item['vendor_id'];
        $postingKey = 'store-purchase:expire-buy:' . hash('sha256', $itemId . "\n" . $amount . "\n" . $token);
        $reference = substr($postingKey, strlen('store-purchase:'));

        return $this->ledger->post(new JournalDraft(
            postingKey: $postingKey,
            postingDate: $postedOn,
            source: new JournalSource('store_purchase', $vendorId, 'Buy expired item'),
            lines: [
                PostedLines::entry($this->ledger->roleAccount('store_purchases'), $amount, 0, null, null),
                PostedLines::entry($this->ledger->roleAccount('vendor_payable'), 0, $amount, 'vendor', $vendorId),
            ],
            beforeCommit: function (string $journalId) use ($itemId, $vendorId, $amount): void {
                $this->statements->change(
                    'INSERT INTO item_buys (journal_id, item_id, vendor_id, amount_minor)
                     VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
                    [$journalId, $itemId, $vendorId, $amount]
                );
                $this->audit->record('item_buy.expire', $journalId);
            },
        ));
    }

    /**
     * @return array{vendor_id: string}
     */
    private function item(string $itemId): array
    {
        $rows = $this->statements->bound(
            'SELECT vendor_id::text AS vendor_id FROM items WHERE id = ?::uuid',
            [$itemId]
        );
        if (count($rows) !== 1) {
            throw new DatabaseRefusal('Choose an item.');
        }
        $vendorId = RowValue::string($rows[0]['vendor_id'] ?? null);
        if ($vendorId === '') {
            throw new DatabaseRefusal('a buy takes a consigned piece');
        }

        return ['vendor_id' => $vendorId];
    }

    private function action(string $value): string
    {
        return in_array($value, ['return', 'donate', 'buy'], true) ? $value : 'return';
    }
}
