<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use PerfectConsign\Ledger\PostingFunctions;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * The nightly late fee run. The database decides which fees are due (an
 * unpaid rent period takes a fee each time grace passes, the first amount then
 * the next), for how much, on which day, and posts each one once. A waived fee
 * is never posted again.
 */
final class LateFeeDue
{
    public function __construct(
        private readonly Statements $statements,
        private readonly PostingFunctions $postings,
        private readonly LateFee $fees,
    ) {
    }

    public function waive(string $journalId, string $date, string $note = ''): string
    {
        return $this->fees->waive($journalId, $date, $note);
    }

    /**
     * Posts every fee due through today and returns how many were posted.
     */
    public function apply(string $today): int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
            return 0;
        }
        $dates = [];
        foreach (
            $this->statements->bound(
                "SELECT DISTINCT to_char(due.fee_date, 'YYYY-MM-DD') AS fee_date FROM stall_late_fees_due(?::date) AS due",
                [$today]
            ) as $row
        ) {
            $dates[] = RowValue::string($row['fee_date'] ?? null);
        }
        if ($dates === []) {
            return 0;
        }

        return $this->postings->postBatch($dates, 'stall_post_late_fees(?::date)', [$today]);
    }
}
