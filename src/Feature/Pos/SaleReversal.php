<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Bootstrap\SaleCeiling;
use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class SaleReversal
{
    public function __construct(
        private readonly LedgerStore $ledger,
        private readonly Statements $statements,
        private readonly SaleCeiling $ceiling,
        private readonly VendorClawback $clawback,
    ) {
    }

    public function voidSale(
        string $sessionId,
        string $date,
        string $journalId,
        string $overrideUser = '',
        string $overridePassword = '',
    ): string {
        $journal = $this->sale($journalId);
        if ($this->ledger->reversed($journalId) || self::flag($journal['is_reversal'] ?? null)) {
            throw new DatabaseRefusal('a second void is refused');
        }
        $this->clawbackBeforeVoid($journalId, $date);

        $posted = $this->ledger->post(new JournalDraft(
            postingKey: 'reversal:' . $journalId,
            postingDate: $date,
            source: new JournalSource(
                'sale',
                RowValue::string($journal['source_reference'] ?? null) . ':void',
                'Void of ' . RowValue::string($journal['posting_key'] ?? null),
            ),
            lines: $this->swapped($journalId),
            reversesJournalId: $journalId,
            sessionId: $sessionId,
        ));
        $this->ceiling->second('sale.void', $posted, $overrideUser, $overridePassword);

        return $posted;
    }

    /**
     * @param array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string} $credit
     */
    public function returnItem(
        string $sessionId,
        string $date,
        string $saleId,
        string $itemId,
        array $credit,
        ?string $check,
    ): string {
        $item = $this->item($saleId, $itemId);
        $openPayable = $this->clawback->openPayable($item['vendor_id']);

        return $this->ledger->post(new JournalDraft(
            postingKey: 'return:' . $saleId . ':' . $itemId,
            postingDate: $date,
            source: new JournalSource('item_return', 'return:' . $itemId, 'Return'),
            lines: SaleJournal::giveBack($this->ledger, $item, $item['vendor_id'], $credit, $openPayable),
            sessionId: $sessionId,
            checkNumber: $check,
            beforeCommit: function (string $journalId) use ($saleId, $itemId, $item): void {
                $this->statements->change(
                    'INSERT INTO item_returns (journal_id, sale_journal_id, item_id, price_minor, quantity, commission_minor, tax_minor, discount_minor, store_discount_minor)
                     VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?, ?, ?, ?, ?)',
                    [$journalId, $saleId, $itemId, $item['price'], $item['quantity'], $item['commission'], $item['tax'], $item['discount'], $item['store_discount']]
                );
            },
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function sale(string $journalId): array
    {
        $journal = $this->ledger->journal($journalId);
        if ($journal === null || RowValue::string($journal['source_type'] ?? null) !== 'sale') {
            throw new DatabaseRefusal(DatabaseRefusal::TICKET_NOT_A_SALE);
        }

        return $journal;
    }

    /**
     * @return array{price: int, quantity: int, commission: int, tax: int, discount: int, store_discount: int, vendor_id: string, placement: string, cost: int}
     */
    private function item(string $saleId, string $itemId): array
    {
        $rows = $this->statements->bound(
            'SELECT sale.price_minor, sale.quantity, sale.commission_minor, sale.tax_minor, sale.discount_minor,
                    sale.store_discount_minor, item.vendor_id, item.placement, item.cost_minor
             FROM sale_lines AS sale
             JOIN items AS item ON item.id = sale.item_id
             JOIN journals AS journal ON journal.id = sale.journal_id
             WHERE sale.journal_id = ?::uuid
               AND sale.item_id = ?::uuid
               AND journal.source_type = ?
               AND journal.is_reversal = false',
            [$saleId, $itemId, 'sale']
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new DatabaseRefusal('that item is not on the ticket');
        }

        $quantity = RowValue::integer($row['quantity'] ?? null);

        return [
            'price' => RowValue::integer($row['price_minor'] ?? null) * $quantity,
            'quantity' => $quantity,
            'commission' => RowValue::integer($row['commission_minor'] ?? null),
            'tax' => RowValue::integer($row['tax_minor'] ?? null),
            'discount' => RowValue::integer($row['discount_minor'] ?? null),
            'store_discount' => RowValue::integer($row['store_discount_minor'] ?? null),
            'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
            'placement' => RowValue::string($row['placement'] ?? null),
            'cost' => ($row['cost_minor'] === null ? 0 : RowValue::integer($row['cost_minor'])) * $quantity,
        ];
    }

    /**
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    private function swapped(string $journalId): array
    {
        $lines = [];
        foreach ($this->ledger->lines($journalId) as $line) {
            $lines[] = PostedLines::entry(
                RowValue::string($line['account_id'] ?? null),
                RowValue::integer($line['credit_minor'] ?? null),
                RowValue::integer($line['debit_minor'] ?? null),
                RowValue::optionalString($line['subledger_type'] ?? null),
                RowValue::optionalString($line['subledger_ref'] ?? null)
            );
        }

        return $lines;
    }

    private function clawbackBeforeVoid(string $journalId, string $date): void
    {
        $payableAccount = $this->ledger->roleAccount('vendor_payable');
        $needed = [];
        foreach ($this->ledger->lines($journalId) as $line) {
            if (RowValue::string($line['account_id'] ?? null) !== $payableAccount) {
                continue;
            }
            if (RowValue::optionalString($line['subledger_type'] ?? null) !== 'vendor') {
                continue;
            }
            $vendorId = RowValue::optionalString($line['subledger_ref'] ?? null);
            $credit = RowValue::integer($line['credit_minor'] ?? null);
            if ($vendorId === null || $credit < 1) {
                continue;
            }
            $needed[$vendorId] = ($needed[$vendorId] ?? 0) + $credit;
        }
        foreach ($needed as $vendorId => $amount) {
            $shortfall = $amount - $this->clawback->openPayable($vendorId);
            if ($shortfall > 0) {
                $this->clawback->reclass($vendorId, $shortfall, $date, $journalId);
            }
        }
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
