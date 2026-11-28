<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\ActorRole;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\PostingFunctions;
use PerfectConsign\Shared\Database\Statements;

/**
 * Writing off what a vendor owes, and taking it back if they pay later. The
 * database decides everything: owner only, a reason, nothing owed to them
 * first, old enough, the exact amounts. PHP passes who is signed in.
 */
final class BadDebts
{
    public function __construct(
        private readonly Statements $statements,
        private readonly PostingFunctions $postings,
        private readonly JournalAudit $audit,
        private readonly Actor $actor,
    ) {
    }

    /**
     * Writes off every debt the vendor owes, today. Returns how many were written off.
     */
    public function writeOff(string $vendorId, string $reason, string $today): int
    {
        [$account, $username] = $this->owner();
        $posted = 0;
        $this->statements->transaction(function () use ($vendorId, $reason, $today, $account, $username, &$posted): void {
            $posted = $this->postings->postBatch(
                [$today],
                'stall_write_off(?::uuid, ?, ?::uuid, ?)',
                [$vendorId, $reason, $account, $username]
            );
            $this->audit->record('vendor.write_off', 'vendor', $vendorId);
        });

        return $posted;
    }

    /**
     * Takes back up to what one write-off took, when the vendor pays after it.
     */
    public function recover(string $writeOffId, int $amountMinor, string $today): string
    {
        [$account, $username] = $this->owner();
        $journal = '';
        $this->statements->transaction(function () use ($writeOffId, $amountMinor, $today, $account, $username, &$journal): void {
            $journal = $this->postings->post(
                $today,
                'stall_write_off_recover(?::uuid, ?, ?::uuid, ?)',
                [$writeOffId, $amountMinor, $account, $username]
            );
            $this->audit->record('vendor.write_off_recover', 'journal', $journal);
        });

        return $journal;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function owner(): array
    {
        $account = $this->actor->account();
        $username = $this->actor->username();
        if ($this->actor->role() !== ActorRole::OWNER || $account === '' || $username === '') {
            throw new DatabaseRefusal('Only the owner can do that.');
        }

        return [$account, $username];
    }
}
