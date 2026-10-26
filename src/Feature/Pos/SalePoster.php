<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Scalar\RowValue;

final class SalePoster
{
    public function __construct(
        private readonly LedgerStore $ledger,
        private readonly SaleReceipt $receipts,
        private readonly ReceiptChange $change,
        private readonly SaleTicketAttach $tickets,
        private readonly SaleReversal $reversals,
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
            $existing = $this->tickets->posted($offered);
            if ($existing !== null) {
                return $existing;
            }
        }
        if ($items === []) {
            throw new DatabaseRefusal('a ticket needs an item');
        }
        $this->change->accept($cart['due'], $tender['sum'], $tender['cash'], $roundOn, $handed);
        $ticketId = $cart['ticket_id'];
        $postingKey = $this->tickets->saleKey($ticketId, $offered);
        $existing = $this->tickets->posted($postingKey);
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
                $this->tickets->attach($journalId, $ticketId, $items);
                $this->tickets->clearTenders($ticketId);
            },
            tenderedMinor: $handed,
        ));
        } catch (DatabaseRefusal $refusal) {
            $again = $this->tickets->posted($postingKey);
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

        return $this->tickets->posted($postingKey);
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
    ): string {
        return $this->reversals->voidSale($sessionId, $date, $journalId, $overrideUser, $overridePassword);
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
        return $this->reversals->returnItem($sessionId, $date, $saleId, $itemId, $credit, $check);
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
}
