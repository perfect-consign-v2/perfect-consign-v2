<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;

/**
 * Waives one late fee. The database reverses it on its stay, once, keeps the
 * note, and never charges that fee again.
 */
final class LateFee
{
    public function __construct(private readonly LedgerStore $ledger)
    {
    }

    public function waive(string $journalId, string $date, string $note = ''): string
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $journalId) !== 1) {
            throw new DatabaseRefusal('Choose a late fee.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new DatabaseRefusal('Enter the date of the late fee.');
        }

        return $this->ledger->postThrough(
            $date,
            'stall_waive_late_fee(?::uuid, ?::date, ?)',
            [$journalId, $date, trim($note)],
        );
    }
}
