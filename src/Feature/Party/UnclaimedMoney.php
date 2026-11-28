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
 * Money the store owes a vendor it cannot reach: moved to unclaimed money,
 * paid to the vendor if they come back, or sent to the state. The database
 * decides who, when and how much; PHP passes who is signed in.
 */
final class UnclaimedMoney
{
    public function __construct(
        private readonly Statements $statements,
        private readonly PostingFunctions $postings,
        private readonly JournalAudit $audit,
        private readonly Actor $actor,
    ) {
    }

    public function move(string $vendorId, string $reason, string $today): string
    {
        [$account, $username] = $this->owner();

        return $this->posted('vendor.unclaimed_move', $vendorId, fn (): string => $this->postings->post(
            $today,
            'stall_move_to_unclaimed(?::uuid, ?, ?::uuid, ?)',
            [$vendorId, $reason, $account, $username]
        ));
    }

    /**
     * Pays the vendor from what is held: cash on the open register, or a check.
     */
    public function pay(string $vendorId, int $amountMinor, string $way, string $checkNumber, string $sessionId, string $today): string
    {
        [$account, $username] = $this->owner();
        if ($way === 'cash' && $sessionId === '') {
            throw new DatabaseRefusal('Open the register first to take or give back money.');
        }

        return $this->posted('vendor.unclaimed_pay', $vendorId, fn (): string => $this->postings->post(
            $today,
            'stall_unclaimed_pay(?::uuid, ?, ?, ?, ?::uuid, ?)',
            [$vendorId, $amountMinor, $way, $checkNumber, $account, $username],
            $way === 'cash' ? $sessionId : null,
        ));
    }

    public function remit(string $vendorId, int $amountMinor, string $stateReference, string $today): string
    {
        [$account, $username] = $this->owner();

        return $this->posted('vendor.unclaimed_remit', $vendorId, fn (): string => $this->postings->post(
            $today,
            'stall_unclaimed_remit(?::uuid, ?, ?, ?::uuid, ?)',
            [$vendorId, $amountMinor, $stateReference, $account, $username]
        ));
    }

    /**
     * @param callable(): string $post
     */
    private function posted(string $action, string $vendorId, callable $post): string
    {
        $journal = '';
        $this->statements->transaction(function () use ($action, $vendorId, $post, &$journal): void {
            $journal = $post();
            $this->audit->record($action, 'vendor', $vendorId);
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
