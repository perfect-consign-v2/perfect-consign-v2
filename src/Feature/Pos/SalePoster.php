<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\CashRound;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class SalePoster
{
    public function __construct(
        private readonly LedgerStore $ledger,
        private readonly Statements $statements,
    ) {
    }

    /**
     * @param array{
     *     ticket_id: string,
     *     lines: list<array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, price: int, commission: int, tax: int}>,
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
        if ($tender['sum'] !== $cart['due']) {
            throw new DatabaseRefusal('the tenders must add up to the amount due');
        }
        $this->guardCash($tender['cash'], $roundOn, $handed);
        $ticketId = $cart['ticket_id'];

        return $this->ledger->post(
            'sale:' . $ticketId,
            $date,
            'sale',
            'sale:' . $ticketId,
            'Sale',
            SaleJournal::sale($this->ledger, $pieces, $tender['lines'], $tender['cash'], $roundOn),
            null,
            $sessionId,
            $tender['check'],
            function (string $journalId) use ($ticketId, $pieces): void {
                $this->attach($journalId, $ticketId, $pieces);
            }
        );
    }

    public function voidSale(string $sessionId, string $date, string $journalId): string
    {
        $journal = $this->sale($journalId);
        if ($this->ledger->reversed($journalId) || self::flag($journal['is_reversal'] ?? null)) {
            throw new DatabaseRefusal('a second void is refused');
        }

        return $this->ledger->post(
            'reversal:' . $journalId,
            $date,
            'sale',
            RowValue::string($journal['source_reference'] ?? null) . ':void',
            'Void of ' . RowValue::string($journal['posting_key'] ?? null),
            $this->swapped($journalId),
            $journalId,
            $sessionId
        );
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

        return $this->ledger->post(
            'return:' . $saleId . ':' . $itemId,
            $date,
            'piece_return',
            'return:' . $itemId,
            'Return',
            SaleJournal::giveBack(
                $this->ledger,
                $piece['price'],
                $piece['commission'],
                $piece['tax'],
                $piece['vendor_id'],
                $credit
            ),
            null,
            $sessionId,
            $check,
            function (string $journalId) use ($saleId, $itemId, $piece): void {
                $this->statements->change(
                    'INSERT INTO piece_returns (journal_id, sale_journal_id, item_id, price_minor, commission_minor, tax_minor)
                     VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?, ?)',
                    [$journalId, $saleId, $itemId, $piece['price'], $piece['commission'], $piece['tax']]
                );
            }
        );
    }

    /**
     * @return array{
     *     journal_id: string,
     *     posting_key: string,
     *     description: string,
     *     voided: bool,
     *     lines: list<array{item_id: string, code: string, description: string, vendor: string, price: int, commission: int, tax: int, returned: bool}>,
     *     tenders: list<array{name: string, amount: int}>,
     *     round: int,
     *     cash: int
     * }
     */
    public function receipt(string $journalId): array
    {
        $journal = $this->sale($journalId);
        $money = $this->money($journalId);

        return [
            'journal_id' => $journalId,
            'posting_key' => RowValue::string($journal['posting_key'] ?? null),
            'description' => RowValue::string($journal['description'] ?? null),
            'voided' => $this->ledger->reversed($journalId) || self::flag($journal['is_reversal'] ?? null),
            'lines' => $this->receiptLines($journalId),
            'tenders' => $money['tenders'],
            'round' => $money['round'],
            'cash' => $money['cash'],
        ];
    }

    /**
     * @param list<array{line_no: int, item_id: string, price: int, commission: int, tax: int}> $pieces
     */
    private function attach(string $journalId, string $ticketId, array $pieces): void
    {
        $this->statements->bound(
            'SELECT id FROM tickets WHERE id = ?::uuid AND status = ? FOR UPDATE',
            [$ticketId, 'open']
        );
        $this->samePrices($ticketId, $pieces);
        foreach ($pieces as $piece) {
            $this->statements->change(
                'INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
                 VALUES (?::uuid, ?, ?::uuid, ?, ?, ?)',
                [$journalId, $piece['line_no'], $piece['item_id'], $piece['price'], $piece['commission'], $piece['tax']]
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
     * @param list<array{item_id: string, price: int}> $pieces
     */
    private function samePrices(string $ticketId, array $pieces): void
    {
        $rows = $this->statements->bound(
            'SELECT item_id, price_minor FROM ticket_lines WHERE ticket_id = ?::uuid ORDER BY line_no',
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
     * @param array{item_id: string, price: int} $piece
     */
    private function samePiece(mixed $row, array $piece): void
    {
        if (!is_array($row)
            || RowValue::string($row['item_id'] ?? null) !== $piece['item_id']
            || RowValue::integer($row['price_minor'] ?? null) !== $piece['price']) {
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
     * @return array{price: int, commission: int, tax: int, vendor_id: string}
     */
    private function piece(string $saleId, string $itemId): array
    {
        $rows = $this->statements->bound(
            'SELECT sale.price_minor, sale.commission_minor, sale.tax_minor, item.vendor_id
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

        return [
            'price' => RowValue::integer($row['price_minor'] ?? null),
            'commission' => RowValue::integer($row['commission_minor'] ?? null),
            'tax' => RowValue::integer($row['tax_minor'] ?? null),
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

    /**
     * @return list<array{item_id: string, code: string, description: string, vendor: string, price: int, commission: int, tax: int, returned: bool}>
     */
    private function receiptLines(string $journalId): array
    {
        $rows = $this->statements->bound(
            'SELECT sale.item_id, sale.price_minor, sale.commission_minor, sale.tax_minor,
                    item.code, item.description, vendor.name AS vendor_name,
                    EXISTS (
                        SELECT 1 FROM piece_returns AS returned
                        WHERE returned.sale_journal_id = sale.journal_id
                          AND returned.item_id = sale.item_id
                    ) AS returned
             FROM sale_lines AS sale
             JOIN items AS item ON item.id = sale.item_id
             JOIN vendors AS vendor ON vendor.id = item.vendor_id
             WHERE sale.journal_id = ?::uuid
             ORDER BY sale.line_no',
            [$journalId]
        );
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = [
                'item_id' => RowValue::string($row['item_id'] ?? null),
                'code' => RowValue::string($row['code'] ?? null),
                'description' => RowValue::string($row['description'] ?? null),
                'vendor' => RowValue::string($row['vendor_name'] ?? null),
                'price' => RowValue::integer($row['price_minor'] ?? null),
                'commission' => RowValue::integer($row['commission_minor'] ?? null),
                'tax' => RowValue::integer($row['tax_minor'] ?? null),
                'returned' => self::flag($row['returned'] ?? null),
            ];
        }

        return $lines;
    }

    /**
     * @return array{tenders: list<array{name: string, amount: int}>, round: int, cash: int}
     */
    private function money(string $journalId): array
    {
        $rows = $this->statements->bound(
            'SELECT account.name, line.debit_minor, line.credit_minor, role.role
             FROM journal_lines AS line
             JOIN accounts AS account ON account.id = line.account_id
             LEFT JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE line.journal_id = ?::uuid
             ORDER BY line.line_no',
            [$journalId]
        );
        $tenders = [];
        $round = 0;
        $cash = 0;
        foreach ($rows as $row) {
            $read = $this->moneyLine($row);
            if ($read['tender'] !== null) {
                $tenders[] = $read['tender'];
            }
            $round += $read['round'];
            $cash += $read['cash'];
        }

        return ['tenders' => $tenders, 'round' => $round, 'cash' => $cash];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{tender: ?array{name: string, amount: int}, round: int, cash: int}
     */
    private function moneyLine(array $row): array
    {
        $role = is_string($row['role'] ?? null) ? $row['role'] : '';
        $debit = RowValue::integer($row['debit_minor'] ?? null);
        $credit = RowValue::integer($row['credit_minor'] ?? null);
        if ($role === 'cash_rounding') {
            return ['tender' => null, 'round' => $credit - $debit, 'cash' => 0];
        }
        if ($debit < 1 || !$this->tenderRole($role)) {
            return ['tender' => null, 'round' => 0, 'cash' => 0];
        }

        return [
            'tender' => ['name' => RowValue::string($row['name'] ?? null), 'amount' => $debit],
            'round' => 0,
            'cash' => $role === 'cash' ? $debit : 0,
        ];
    }

    private function tenderRole(string $role): bool
    {
        return in_array($role, ['cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit'], true);
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
