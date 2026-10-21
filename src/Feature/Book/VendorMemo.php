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

final class VendorMemo
{
    public function __construct(
        private readonly LedgerStore $ledger,
        private readonly Statements $statements,
    ) {
    }

    public function openPayable(string $vendorId): int
    {
        $vendor = $this->statements->bound(
            'SELECT id::text AS id FROM vendors WHERE id = ?::uuid',
            [$vendorId]
        );
        if ($vendor === []) {
            throw new DatabaseRefusal('a vendor memo needs a vendor');
        }
        $rows = $this->statements->bound(
            'SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) AS owed
             FROM journal_lines AS line
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = \'vendor_payable\'
               AND line.subledger_type = \'vendor\'
               AND line.subledger_ref = ?',
            [$vendorId]
        );

        return RowValue::integer($rows[0]['owed'] ?? null);
    }

    public function record(string $vendorId, string $direction, int $amount, string $postingDate, string $reason): string
    {
        $trimmed = trim($reason);
        if ($trimmed === '') {
            throw new InvalidArgumentException('a vendor memo needs a reason');
        }
        if ($direction !== 'credit' && $direction !== 'debit') {
            throw new InvalidArgumentException('a vendor memo credits or debits the vendor');
        }
        if ($amount < 1) {
            throw new InvalidArgumentException('a vendor memo is a positive amount');
        }
        $reference = bin2hex(random_bytes(16));
        $lines = $direction === 'credit'
            ? [
                PostedLines::entry($this->ledger->roleAccount('vendor_allowance'), $amount, 0, null, null),
                PostedLines::entry($this->ledger->roleAccount('vendor_payable'), 0, $amount, 'vendor', $vendorId),
            ]
            : [
                PostedLines::entry($this->ledger->roleAccount('vendor_payable'), $amount, 0, 'vendor', $vendorId),
                PostedLines::entry($this->ledger->roleAccount('vendor_charge'), 0, $amount, null, null),
            ];

        return $this->ledger->post(new JournalDraft(
            postingKey: 'vendor-memo:' . $reference,
            postingDate: $postingDate,
            source: new JournalSource('vendor_memo', $reference, 'Vendor memo'),
            lines: $lines,
            beforeCommit: function (string $journalId) use ($vendorId, $direction, $amount, $trimmed): void {
                $this->statements->change(
                    'INSERT INTO vendor_memos (journal_id, vendor_id, direction, amount_minor, reason)
                     VALUES (?::uuid, ?::uuid, ?, ?, ?)',
                    [$journalId, $vendorId, $direction, $amount, $trimmed]
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
}
