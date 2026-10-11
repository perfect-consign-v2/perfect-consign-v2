<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class SaleReceipt
{
    public function __construct(
        private readonly LedgerStore $ledger,
        private readonly Statements $statements,
    ) {
    }

    /**
     * @param array<string, mixed> $journal
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
    public function read(string $journalId, array $journal): array
    {
        $money = $this->money($journalId);
        $lines = $this->lines($journalId);
        $note = $this->note($journalId);
        $discount = 0;
        foreach ($lines as $line) {
            $discount += $line['discount'];
        }

        return [
            'journal_id' => $journalId,
            'posting_key' => RowValue::string($journal['posting_key'] ?? null),
            'sale_number' => RowValue::integer($journal['journal_no'] ?? null),
            'posted_on' => $journal['posting_date'] instanceof \DateTimeInterface
                ? $journal['posting_date']->format('Y-m-d')
                : RowValue::string($journal['posting_date'] ?? null),
            'description' => RowValue::string($journal['description'] ?? null),
            'voided' => $this->ledger->reversed($journalId) || self::flag($journal['is_reversal'] ?? null),
            'lines' => $lines,
            'tenders' => $money['tenders'],
            'round' => $money['round'],
            'cash' => $money['cash'],
            'discount' => $discount,
            'discount_reason' => $note['discount_reason'],
            'waiver' => $note['waiver'],
        ];
    }

    /**
     * @return list<array{item_id: string, code: string, description: string, vendor: string, price: int, quantity: int, commission: int, tax: int, discount: int, returned: bool}>
     */
    private function lines(string $journalId): array
    {
        $rows = $this->statements->bound(
            'SELECT sale.item_id, sale.price_minor, sale.quantity, sale.commission_minor, sale.tax_minor, sale.discount_minor,
                    item.code, item.description, vendor.name AS vendor_name,
                    EXISTS (
                        SELECT 1 FROM item_returns AS returned
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
                'quantity' => RowValue::integer($row['quantity'] ?? null),
                'commission' => RowValue::integer($row['commission_minor'] ?? null),
                'tax' => RowValue::integer($row['tax_minor'] ?? null),
                'discount' => RowValue::integer($row['discount_minor'] ?? null),
                'returned' => self::flag($row['returned'] ?? null),
            ];
        }

        return $lines;
    }

    /**
     * @return array{discount_reason: string, waiver: string}
     */
    private function note(string $journalId): array
    {
        $rows = $this->statements->bound(
            'SELECT discount_reason, tax_waiver_reason FROM tickets WHERE posted_journal_id = ?::uuid',
            [$journalId]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return ['discount_reason' => '', 'waiver' => ''];
        }
        $reason = $row['discount_reason'] ?? null;
        $waiver = $row['tax_waiver_reason'] ?? null;

        return [
            'discount_reason' => is_string($reason) ? $reason : '',
            'waiver' => is_string($waiver) ? $waiver : '',
        ];
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
