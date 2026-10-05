<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\CashRound;
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
    ) {
    }

    /**
     * @param array{
     *     ticket_id: string,
     *     lines: list<array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, price: int, quantity: int, commission: int, tax: int, discount: int}>,
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
        $pieces = $cart['lines'];
        if ($pieces === []) {
            throw new DatabaseRefusal('a ticket needs a piece');
        }
        $cashOnly = $tender['cash'] > 0 && $tender['cash'] === $tender['sum'];
        $settled = $roundOn === 1 && $cashOnly ? CashRound::rounded($cart['due']) : $cart['due'];
        if ($tender['sum'] !== $cart['due'] && $tender['sum'] !== $settled) {
            throw new DatabaseRefusal('the tenders must add up to the amount due');
        }
        $this->guardCash($tender['cash'], $roundOn, $handed);
        $ticketId = $cart['ticket_id'];

        return $this->ledger->post(new JournalDraft(
            postingKey: 'sale:' . $ticketId,
            postingDate: $date,
            source: new JournalSource('sale', 'sale:' . $ticketId, 'Sale'),
            lines: SaleJournal::sale($this->ledger, $pieces, $tender['lines'], $tender['cash'], $roundOn, $cart['due']),
            sessionId: $sessionId,
            checkNumber: $tender['check'],
            beforeCommit: function (string $journalId) use ($ticketId, $pieces): void {
                $this->attach($journalId, $ticketId, $pieces);
                $this->statements->change('DELETE FROM ticket_tenders WHERE ticket_id = ?::uuid', [$ticketId]);
            },
            tenderedMinor: $handed,
        ));
    }

    public function voidSale(string $sessionId, string $date, string $journalId): string
    {
        $journal = $this->sale($journalId);
        if ($this->ledger->reversed($journalId) || self::flag($journal['is_reversal'] ?? null)) {
            throw new DatabaseRefusal('a second void is refused');
        }

        return $this->ledger->post(new JournalDraft(
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
    }

    /**
     * @param array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string} $credit
     */
    public function returnPiece(
        string $sessionId,
        string $date,
        string $saleId,
        string $itemId,
        array $credit,
        ?string $check,
    ): string {
        $piece = $this->piece($saleId, $itemId);

        return $this->ledger->post(new JournalDraft(
            postingKey: 'return:' . $saleId . ':' . $itemId,
            postingDate: $date,
            source: new JournalSource('piece_return', 'return:' . $itemId, 'Return'),
            lines: SaleJournal::giveBack(
                $this->ledger,
                $piece['price'],
                $piece['commission'],
                $piece['tax'],
                $piece['discount'],
                $piece['vendor_id'],
                $credit
            ),
            sessionId: $sessionId,
            checkNumber: $check,
            beforeCommit: function (string $journalId) use ($saleId, $itemId, $piece): void {
                $this->statements->change(
                    'INSERT INTO piece_returns (journal_id, sale_journal_id, item_id, price_minor, quantity, commission_minor, tax_minor, discount_minor)
                     VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?, ?, ?, ?)',
                    [$journalId, $saleId, $itemId, $piece['price'], $piece['quantity'], $piece['commission'], $piece['tax'], $piece['discount']]
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
     *     lines: list<array{item_id: string, code: string, description: string, vendor: string, price: int, quantity: int, commission: int, tax: int, discount: int, returned: bool}>,
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

    /**
     * @param list<array{line_no: int, item_id: string, price: int, quantity: int, commission: int, tax: int, discount: int}> $pieces
     */
    private function attach(string $journalId, string $ticketId, array $pieces): void
    {
        $locked = $this->statements->bound(
            'SELECT id FROM tickets WHERE id = ?::uuid AND status = ? FOR UPDATE',
            [$ticketId, 'open']
        );
        if ($locked === []) {
            throw new DatabaseRefusal('a posted ticket cannot change');
        }
        $this->samePrices($ticketId, $pieces);
        foreach ($pieces as $piece) {
            $this->statements->change(
                'INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, quantity, commission_minor, tax_minor, discount_minor)
                 VALUES (?::uuid, ?, ?::uuid, ?, ?, ?, ?, ?)',
                [$journalId, $piece['line_no'], $piece['item_id'], $piece['price'], $piece['quantity'], $piece['commission'], $piece['tax'], $piece['discount']]
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
     * @param list<array{item_id: string, price: int, quantity: int}> $pieces
     */
    private function samePrices(string $ticketId, array $pieces): void
    {
        $rows = $this->statements->bound(
            'SELECT item_id, price_minor, quantity FROM ticket_lines WHERE ticket_id = ?::uuid ORDER BY line_no',
            [$ticketId]
        );
        if (count($rows) !== count($pieces)) {
            throw new DatabaseRefusal('the ticket changed before pay');
        }
        foreach ($pieces as $index => $piece) {
            $this->samePiece($rows[$index] ?? null, $piece);
        }
    }

    /**
     * @param array{item_id: string, price: int, quantity: int} $piece
     */
    private function samePiece(mixed $row, array $piece): void
    {
        if (!is_array($row)
            || RowValue::string($row['item_id'] ?? null) !== $piece['item_id']
            || RowValue::integer($row['price_minor'] ?? null) !== $piece['price']
            || RowValue::integer($row['quantity'] ?? null) !== $piece['quantity']) {
            throw new DatabaseRefusal('the ticket changed before pay');
        }
    }

    private function guardCash(int $cash, int $roundOn, ?int $handed): void
    {
        if ($handed === null || $cash < 1) {
            return;
        }
        $due = $roundOn === 1 ? $cash + CashRound::adjustment($cash) : $cash;
        if ($handed < $due) {
            throw new DatabaseRefusal('the cash received is less than the cash due');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sale(string $journalId): array
    {
        $journal = $this->ledger->journal($journalId);
        if ($journal === null || RowValue::string($journal['source_type'] ?? null) !== 'sale') {
            throw new DatabaseRefusal('that ticket is not a sale');
        }

        return $journal;
    }

    /**
     * @return array{price: int, quantity: int, commission: int, tax: int, discount: int, vendor_id: string}
     */
    private function piece(string $saleId, string $itemId): array
    {
        $rows = $this->statements->bound(
            'SELECT sale.price_minor, sale.quantity, sale.commission_minor, sale.tax_minor, sale.discount_minor, item.vendor_id
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
            throw new DatabaseRefusal('that piece is not on the ticket');
        }

        $quantity = RowValue::integer($row['quantity'] ?? null);

        return [
            'price' => RowValue::integer($row['price_minor'] ?? null) * $quantity,
            'quantity' => $quantity,
            'commission' => RowValue::integer($row['commission_minor'] ?? null),
            'tax' => RowValue::integer($row['tax_minor'] ?? null),
            'discount' => RowValue::integer($row['discount_minor'] ?? null),
            'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
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

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
