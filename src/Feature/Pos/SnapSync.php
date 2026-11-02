<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class SnapSync
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<string>
     */
    public function mint(string $sessionId, string $ticketId): array
    {
        $rows = $this->statements->bound(
            'SELECT stall_mint_register_snaps(?::uuid, ?::uuid) AS posting_key',
            [$sessionId, $ticketId]
        );
        $keys = [];
        foreach ($rows as $row) {
            $key = RowValue::optionalString($row['posting_key'] ?? null);
            if ($key === null || $key === '') {
                continue;
            }
            $keys[] = $key;
        }

        return $keys;
    }

    public function sync(string $postingKey): ?int
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $postingKey) !== 1) {
            throw new DatabaseRefusal('that snap is not on this register');
        }
        $rows = $this->statements->bound(
            'SELECT stall_sync_snap(?) AS journal_no',
            [$postingKey]
        );
        $number = $rows[0]['journal_no'] ?? null;
        if ($number === null || $number === '') {
            return null;
        }

        return RowValue::integer($number);
    }

    public function refusal(string $postingKey): string
    {
        $rows = $this->statements->bound(
            'SELECT refusal FROM register_snaps WHERE posting_key = ?',
            [$postingKey]
        );

        return RowValue::optionalString($rows[0]['refusal'] ?? null) ?? '';
    }
}
