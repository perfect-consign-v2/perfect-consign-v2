<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class BoothCorrection
{
    public function __construct(
        private readonly Statements $statements,
        private readonly LedgerStore $ledger,
    ) {
    }

    public function move(string $date, string $journalId, string $itemId, string $toVendorId): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $toVendorId) !== 1) {
            throw new DatabaseRefusal('Choose the booth that should have been paid.');
        }
        $line = $this->line($journalId, $itemId);
        if ($line['vendor_id'] === $toVendorId) {
            throw new DatabaseRefusal('Choose the booth that should have been paid.');
        }
        if ($line['commission'] < 1) {
            throw new DatabaseRefusal('That piece did not pay a vendor.');
        }

        return $this->ledger->post(new JournalDraft(
            'booth-correction:' . $journalId . ':' . $itemId,
            $date,
            new JournalSource('booth_correction', $journalId, 'Wrong booth'),
            [
                PostedLines::entry($this->ledger->roleAccount('vendor_payable'), $line['commission'], 0, 'vendor', $line['vendor_id']),
                PostedLines::entry($this->ledger->roleAccount('vendor_payable'), 0, $line['commission'], 'vendor', $toVendorId),
            ],
        ));
    }

    /**
     * @return array{vendor_id: string, commission: int}
     */
    private function line(string $journalId, string $itemId): array
    {
        $rows = $this->statements->bound(
            'SELECT item.vendor_id, sale.commission_minor
             FROM sale_lines AS sale
             JOIN items AS item ON item.id = sale.item_id
             WHERE sale.journal_id = ?::uuid AND sale.item_id = ?::uuid',
            [$journalId, $itemId]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row) || !is_string($row['vendor_id'] ?? null)) {
            throw new DatabaseRefusal('that piece is not on the ticket');
        }

        return [
            'vendor_id' => $row['vendor_id'],
            'commission' => RowValue::integer($row['commission_minor'] ?? null),
        ];
    }
}
