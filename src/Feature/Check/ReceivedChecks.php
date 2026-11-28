<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Check;

use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Ledger\PostingFunctions;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Database\PgBool;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * Checks the store took, found by their number, and marking one as bounced.
 * The database finds the check and posts its own amount; nothing is typed but
 * the number.
 */
final class ReceivedChecks
{
    public function __construct(
        private readonly Statements $statements,
        private readonly PostingFunctions $postings,
        private readonly JournalAudit $audit,
    ) {
    }

    /**
     * @return list<array{journal_id: string, check_number: string, received_on: string, amount_minor: int, said: string, bounced: bool}>
     */
    public function find(string $number): array
    {
        if ($number === '') {
            return [];
        }
        $found = [];
        foreach (
            $this->statements->bound(
                'SELECT journal_id::text AS journal_id, check_number, received_on::text AS received_on,
                        amount_minor, said, bounced
                 FROM stall_received_checks(?)',
                [$number]
            ) as $row
        ) {
            $found[] = [
                'journal_id' => RowValue::string($row['journal_id'] ?? null),
                'check_number' => RowValue::string($row['check_number'] ?? null),
                'received_on' => RowValue::string($row['received_on'] ?? null),
                'amount_minor' => RowValue::integer($row['amount_minor'] ?? null),
                'said' => RowValue::string($row['said'] ?? null),
                'bounced' => PgBool::read($row['bounced'] ?? null),
            ];
        }

        return $found;
    }

    public function bounce(string $journalId, string $postingDate): string
    {
        return $this->postings->post(
            $postingDate,
            'stall_return_check(?::uuid, ?::date)',
            [$journalId, $postingDate],
            beforeCommit: function (string $posted): void {
                $this->audit->record('check.return', 'journal', $posted);
            },
        );
    }
}
