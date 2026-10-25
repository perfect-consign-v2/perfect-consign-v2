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
     * @param array{
     *     session_id: string,
     *     posting_key: string,
     *     business_date: string,
     *     item_id: string,
     *     price: int,
     *     tax: int,
     *     commission: int,
     *     quantity: int,
     *     commission_bps: int,
     *     tax_bps: int
     * } $snap
     */
    public function save(array $snap): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $snap['posting_key']) !== 1) {
            throw new DatabaseRefusal('that snap is not on this register');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $snap['business_date']) !== 1) {
            throw new DatabaseRefusal('a snapped sale needs its business date');
        }
        $this->statements->change(
            'INSERT INTO register_snaps (
                posting_key, session_id, business_date, item_id, price_minor, tax_minor, commission_minor,
                quantity, commission_bps, tax_bps
             ) VALUES (?, ?::uuid, ?::date, ?::uuid, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (posting_key) DO NOTHING',
            [
                $snap['posting_key'],
                $snap['session_id'],
                $snap['business_date'],
                $snap['item_id'],
                $snap['price'],
                $snap['tax'],
                $snap['commission'],
                $snap['quantity'],
                $snap['commission_bps'],
                $snap['tax_bps'],
            ]
        );
    }

    public function sync(string $postingKey): ?int
    {
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
