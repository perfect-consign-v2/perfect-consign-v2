<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Register;

use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class RegisterSession
{
    public function __construct(
        private readonly Statements $statements,
        private readonly LedgerStore $ledger,
        private readonly MallClock $clock,
        private readonly RegisterActivity $activity,
    ) {
    }

    /**
     * @param array<int, int> $counts
     */
    public function open(string $registerId, string $cashier, array $counts, int $typedFloat): string
    {
        $total = DenominationCount::total($counts);
        if ($total === 0) {
            $counts = DenominationCount::forAmount($typedFloat);
            $total = $typedFloat;
        } elseif ($total !== $typedFloat) {
            throw new DatabaseRefusal('the bills and coins must add up to the opening float');
        }
        $rows = $this->statements->bound(
            'INSERT INTO register_sessions (register_id, cashier, opening_counts, opening_cash_minor)
             VALUES (?::uuid, ?, ?::jsonb, ?)
             RETURNING id',
            [$registerId, $this->cashier($cashier), DenominationCount::json($counts), $total]
        );
        if ($rows === []) {
            throw new DatabaseRefusal('the register did not open');
        }

        return RowValue::string($rows[0]['id'] ?? null);
    }

    /**
     * @param array<int, int> $counts
     */
    public function close(string $registerId, array $counts, int $typedCash, int $card, int $checks, bool $confirmed, string $postingDate): string
    {
        $sessionId = $this->openSessionId($registerId);
        $fromBills = DenominationCount::total($counts);
        if ($fromBills === 0) {
            $counts = DenominationCount::forAmount($typedCash);
        } elseif ($fromBills !== $typedCash) {
            throw new DatabaseRefusal('the bills and coins must add up to the counted cash');
        }
        $counted = $typedCash;
        $expected = $this->activity->drawer($sessionId)['expected'];
        $difference = $counted - $expected;
        if ($difference !== 0 && !$confirmed) {
            throw new DatabaseRefusal('counted cash does not match the expected drawer');
        }
        $json = DenominationCount::json($counts);
        if ($difference === 0) {
            $this->writeClose($sessionId, $json, $counted, $card, $checks);

            return $sessionId;
        }
        $this->postVariance($sessionId, $json, $counted, $card, $checks, $difference, $postingDate);

        return $sessionId;
    }

    public function drop(string $registerId, int $amount, string $postingDate): void
    {
        $sessionId = $this->openSessionId($registerId);
        $openedOn = $this->clock->localDate($this->openedAt($registerId));
        if ($openedOn !== '' && $postingDate < $openedOn) {
            throw new DatabaseRefusal('A cash drop cannot be dated before the register was opened.');
        }
        $key = $this->ledger->newId();
        $this->ledger->post(new JournalDraft(
            postingKey: 'cash-drop:' . $key,
            postingDate: $postingDate,
            source: new JournalSource('cash_drop', $key, 'Cash drop'),
            lines: [
                PostedLines::entry($this->ledger->roleAccount('cash_in_safe'), $amount, 0, null, null),
                PostedLines::entry($this->ledger->roleAccount('cash'), 0, $amount, null, null),
            ],
            sessionId: $sessionId,
        ));
    }

    private function postVariance(
        string $sessionId,
        string $json,
        int $counted,
        int $card,
        int $checks,
        int $difference,
        string $postingDate,
    ): void {
        $amount = abs($difference);
        $cash = $this->ledger->roleAccount('cash');
        $short = $this->ledger->roleAccount('cash_over_short');
        $lines = $difference > 0
            ? [
                PostedLines::entry($cash, $amount, 0, null, null),
                PostedLines::entry($short, 0, $amount, null, null),
            ]
            : [
                PostedLines::entry($short, $amount, 0, null, null),
                PostedLines::entry($cash, 0, $amount, null, null),
            ];
        $key = $this->ledger->newId();
        $this->ledger->post(new JournalDraft(
            postingKey: 'register-variance:' . $key,
            postingDate: $postingDate,
            source: new JournalSource('register_variance', $key, 'Register over/short'),
            lines: $lines,
            sessionId: $sessionId,
            beforeCommit: function (string $journalId) use ($sessionId, $json, $counted, $card, $checks): void {
                if ($journalId === '') {
                    throw new DatabaseRefusal('the register did not close');
                }
                $this->writeClose($sessionId, $json, $counted, $card, $checks);
            },
        ));
    }

    private function writeClose(string $sessionId, string $json, int $cash, int $card, int $checks): void
    {
        $this->statements->change(
            "DELETE FROM tickets
             WHERE session_id = ?::uuid
               AND status = 'open'
               AND NOT EXISTS (SELECT 1 FROM ticket_lines WHERE ticket_id = tickets.id)",
            [$sessionId]
        );
        $updated = $this->statements->affected(
            'UPDATE register_sessions
             SET closing_counts = ?::jsonb,
                 counted_cash_minor = ?,
                 counted_card_minor = ?,
                 counted_checks_minor = ?,
                 closed_at = clock_timestamp()
             WHERE id = ?::uuid AND closed_at IS NULL',
            [$json, $cash, $card, $checks, $sessionId]
        );
        if ($updated !== 1) {
            throw new DatabaseRefusal('the register is not open');
        }
    }

    private function openedAt(string $registerId): string
    {
        $rows = $this->statements->bound(
            'SELECT opened_at FROM register_sessions WHERE register_id = ?::uuid AND closed_at IS NULL',
            [$registerId]
        );
        if ($rows === []) {
            return '';
        }

        return is_string($rows[0]['opened_at'] ?? null) ? $rows[0]['opened_at'] : '';
    }

    private function openSessionId(string $registerId): string
    {
        $rows = $this->statements->bound(
            'SELECT id FROM register_sessions WHERE register_id = ?::uuid AND closed_at IS NULL',
            [$registerId]
        );
        if ($rows === []) {
            throw new DatabaseRefusal('a drawer journal needs an open register');
        }

        return RowValue::string($rows[0]['id'] ?? null);
    }

    private function cashier(string $cashier): string
    {
        if ($cashier === '' || $cashier !== trim($cashier) || strlen($cashier) > 80) {
            throw new \InvalidArgumentException('a cashier name is required');
        }

        return $cashier;
    }
}
