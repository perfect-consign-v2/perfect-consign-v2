<?php

declare(strict_types=1);

namespace PerfectConsign\Ledger;

use PDO;

final class JournalCash
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function tendered(string $journalId): string
    {
        $rows = $this->pdo->prepare(
            'SELECT tendered_minor FROM journal_cash_tendered WHERE journal_id = ?::uuid'
        );
        $rows->execute([$journalId]);
        $minor = $rows->fetchColumn();
        if (!is_int($minor) && !is_string($minor)) {
            return '';
        }
        $cents = (int) $minor;

        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Ties a journal to the drawer it moved cash through, and to the check it was
     * paid with. Both go in the caller's transaction, beside the journal.
     */
    public function linkDrawer(string $journalId, ?string $sessionId, ?string $checkNumber): void
    {
        if ($sessionId !== null) {
            $link = $this->pdo->prepare(
                'INSERT INTO register_session_posts (session_id, journal_id) VALUES (?::uuid, ?::uuid)'
            );
            $link->execute([$sessionId, $journalId]);
        }
        if ($checkNumber === null) {
            return;
        }
        $check = $this->pdo->prepare(
            'INSERT INTO register_checks (journal_id, check_number) VALUES (?::uuid, ?)'
        );
        $check->execute([$journalId, $checkNumber]);
    }

    public function storeTendered(string $journalId, ?int $tenderedMinor): void
    {
        if ($tenderedMinor === null || $tenderedMinor < 1) {
            return;
        }
        $tendered = $this->pdo->prepare(
            'INSERT INTO journal_cash_tendered (journal_id, tendered_minor) VALUES (?::uuid, ?)'
        );
        $tendered->execute([$journalId, $tenderedMinor]);
    }
}
