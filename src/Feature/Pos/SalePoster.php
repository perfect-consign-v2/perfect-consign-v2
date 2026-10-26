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

final class SalePoster
{
    public function __construct(
        private readonly LedgerStore $ledger,
        private readonly Statements $statements,
        private readonly SaleReceipt $receipts,
        private readonly ReceiptChange $change,
        private readonly SaleCeiling $ceiling,
        private readonly VendorClawback $clawback,
    ) {
    }

    /**
     * @param array{
     *     ticket_id: string,
     *     posting_key: string,
     *     lines: list<array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, price: int, quantity: int, commission: int, tax: int, discount: int, store_discount: int}>,
     *     due: int
     * } $cart
     * @param array{
     *     lines: list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>,
     *     sum: int,
     *     check: ?string,
     *     cash: int
     * } $tender
     */
    public function pay(string $sessionId, string $date, int $roundOn, array $cart, array $tender, ?int $handed): string
    {
        $items = $cart['lines'];
        $offered = is_string($cart['posting_key'] ?? null) ? $cart['posting_key'] : '';
        if ($offered !== '') {
            $existing = $this->posted($offered);
            if ($existing !== null) {
                return $existing;
            }
        }
        if ($items === []) {
            throw new DatabaseRefusal('a ticket needs an item');
        }
        $this->change->accept($cart['due'], $tender['sum'], $tender['cash'], $roundOn, $handed);
        $ticketId = $cart['ticket_id'];
        $postingKey = $this->saleKey($ticketId, $offered);
        $existing = $this->posted($postingKey);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->ledger->post(new JournalDraft(
            postingKey: $postingKey,
            postingDate: $date,
            source: new JournalSource('sale', 'sale:' . $ticketId, 'Sale'),
            lines: SaleJournal::sale($this->ledger, $items, $tender['lines'], $tender['cash'], $roundOn, $cart['due']),
            sessionId: $sessionId,
            checkNumber: $tender['check'],
            beforeCommit: function (string $journalId) use ($ticketId, $items): void {
                $this->attach($journalId, $ticketId, $items);
                $this->statements->change('DELETE FROM ticket_tenders WHERE ticket_id = ?::uuid', [$ticketId]);
            },
            tenderedMinor: $handed,
        ));
        } catch (DatabaseRefusal $refusal) {
            $again = $this->posted($postingKey);
            if ($again !== null) {
                return $again;
            }

            throw $refusal;
        }
    }

    public function replay(string $postingKey): ?string
    {
        if ($postingKey === '') {
            return null;
        }

        return $this->posted($postingKey);
    }

    public function number(string $journalId): int
    {
        $journal = $this->ledger->journal($journalId);
        if ($journal === null) {
            throw new DatabaseRefusal('that sale is not posted');
        }

        return RowValue::integer($journal['journal_no'] ?? null);
    }

    public function voidSale(
        string $sessionId,
        string $date,
        string $journalId,
        string $overrideUser = '',
        string $overridePassword = '',
    ): string
    {
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
     * @return array{
     *     journal_id: string,
     *     posting_key: string,
     *     sale_number: int,
     *     posted_on: string,
     *     description: string,
     *     voided: bool,
     *     lines: list<array{item_id: string, code: string, description: string, vendor: string, price: int, quantity: int, commission: int, tax: int, discount: int, store_discount: int, offer_name: string, returned: bool}>,
     *     tenders: list<array{name: string, amount: int}>,
     *     round: int,
     *     cash: int,
     *     discount: int,
     *     discount_reason: string,
     *     waiver: string
     * }
     */
    public function receipt(string $journalId): array
    {
        $journal = $this->sale($journalId);

        return $this->receipts->read($journalId, $journal);
    }

    public function refundAmount(string $journalId, string $itemId): int
    {
        return $this->change->owed($this->receipt($journalId), $itemId);
    }

    private function saleKey(string $ticketId, string $offered): string
    {
        $rows = $this->statements->bound(
            'SELECT posting_key FROM tickets WHERE id = ?::uuid',
            [$ticketId]
        );
        $stored = RowValue::string($rows[0]['posting_key'] ?? null);
        if ($offered !== '' && $offered !== $stored) {
            throw new DatabaseRefusal('the ticket key does not match');
        }

        return $stored;
    }

    private function posted(string $postingKey): ?string
    {
        $rows = $this->statements->bound(
            'SELECT id FROM journals WHERE posting_key = ?',
            [$postingKey]
        );
        if ($rows === []) {
            return null;
        }

        return RowValue::string($rows[0]['id'] ?? null);
    }

    /**
     * @param list<array{line_no: int, item_id: string, price: int, quantity: int, commission: int, tax: int, discount: int, store_discount: int}> $items
     */
    private function attach(string $journalId, string $ticketId, array $items): void
    {
        $locked = $this->statements->bound(
            'SELECT id FROM tickets WHERE id = ?::uuid AND status = ? FOR UPDATE',
            [$ticketId, 'open']
        );
        if ($locked === []) {
            throw new DatabaseRefusal('a posted ticket cannot change');
        }
        $this->samePrices($ticketId, $items);
        foreach ($items as $item) {
            $this->statements->change(
                'INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, quantity, commission_minor, tax_minor, discount_minor, store_discount_minor)
                 VALUES (?::uuid, ?, ?::uuid, ?, ?, ?, ?, ?, ?)',
                [$journalId, $item['line_no'], $item['item_id'], $item['price'], $item['quantity'], $item['commission'], $item['tax'], $item['discount'], $item['store_discount']]
            );
        }
        $updated = $this->statements->affected(
            'UPDATE tickets SET status = ?, posted_journal_id = ?::uuid WHERE id = ?::uuid AND status = ?',
            ['posted', $journalId, $ticketId, 'open']
        );
        if ($updated !== 1) {
            throw new DatabaseRefusal('a posted ticket cannot change');
        }
    }

    /**
     * @param list<array{item_id: string, price: int, quantity: int}> $items
     */
    private function samePrices(string $ticketId, array $items): void
    {
        $rows = $this->statements->bound(
            'SELECT item_id, price_minor, quantity FROM ticket_lines WHERE ticket_id = ?::uuid ORDER BY line_no',
            [$ticketId]
        );
        if (count($rows) !== count($items)) {
            throw new DatabaseRefusal('the ticket changed before pay');
        }
        foreach ($items as $index => $item) {
            $this->sameItem($rows[$index] ?? null, $item);
        }
    }

    /**
     * @param array{item_id: string, price: int, quantity: int} $item
     */
    private function sameItem(mixed $row, array $item): void
    {
        if (!is_array($row)
            || RowValue::string($row['item_id'] ?? null) !== $item['item_id']
            || RowValue::integer($row['price_minor'] ?? null) !== $item['price']
            || RowValue::integer($row['quantity'] ?? null) !== $item['quantity']) {
            throw new DatabaseRefusal('the ticket changed before pay');
        }
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
