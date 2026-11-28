<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Book;

use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Ledger\PostingFunctions;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * A piece the customer took that was never rung. The database works out the
 * vendor's share from the rate it keeps and posts the journal itself; PHP says
 * which vendor, which piece and the price.
 */
final class MissedSale
{
    public function __construct(
        private readonly PostingFunctions $postings,
        private readonly Statements $statements,
        private readonly JournalAudit $audit,
    ) {
    }

    public function vendorShare(string $vendorId, string $itemCode, int $price): int
    {
        $rows = $this->statements->bound(
            'SELECT stall_missed_sale_quote(?::uuid, ?, ?) AS share',
            [$vendorId, $itemCode, $price]
        );

        return RowValue::integer($rows[0]['share'] ?? null);
    }

    public function record(string $vendorId, string $itemCode, int $price, string $postingDate, string $description, string $memo, string $token): string
    {
        return $this->postings->post(
            $postingDate,
            'stall_post_missed_sale(?::uuid, ?, ?, ?::date, ?, ?, ?)',
            [$vendorId, $itemCode, $price, $postingDate, $description, $memo, $token],
            beforeCommit: function (string $journalId): void {
                $fresh = $this->statements->bound(
                    'SELECT (posting_xid = pg_current_xact_id())::text AS fresh FROM journals WHERE id = ?::uuid',
                    [$journalId]
                );
                if (RowValue::string($fresh[0]['fresh'] ?? null) === 'true') {
                    $this->audit->record('missed_sale.record', 'journal', $journalId);
                }
            },
        );
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
