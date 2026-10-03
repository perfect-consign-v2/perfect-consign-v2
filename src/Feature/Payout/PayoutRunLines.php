<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class PayoutRunLines
{
    public function __construct(private readonly Statements $statements)
    {
    }

    public function guard(string $runId, bool $frozen): void
    {
        $function = $frozen ? 'stall_assert_frozen_checksum' : 'stall_assert_pack_checksum';
        $this->statements->change('SELECT ' . $function . '(?::uuid)', [$runId]);
    }

    public function materialize(string $runId): void
    {
        $this->statements->change(
            'INSERT INTO payout_run_lines (
                run_id, vendor_id, mode, sales_minor, commission_minor, tax_minor, discount_minor,
                return_minor, void_minor, draw_minor, check_paid_minor, settlement_applied_minor,
                rent_minor, rent_charged_minor, rent_brought_forward_minor, rent_paid_since_minor,
                adjustment_minor, brought_forward_minor, carried_payable_minor, carried_rent_minor,
                settled_minor, check_minor, owes_minor, net_minor
             )
             SELECT run_id, vendor_id, mode, sales_minor, commission_minor, tax_minor, discount_minor,
                    return_minor, void_minor, draw_minor, check_paid_minor, settlement_applied_minor,
                    rent_minor, rent_charged_minor, rent_brought_forward_minor, rent_paid_since_minor,
                    adjustment_minor, brought_forward_minor, carried_payable_minor, carried_rent_minor,
                    settled_minor, check_minor, owes_minor, net_minor
             FROM payout_pack
             WHERE run_id = ?::uuid
               AND NOT EXISTS (
                   SELECT 1 FROM payout_run_lines AS existing
                   WHERE existing.run_id = payout_pack.run_id AND existing.vendor_id = payout_pack.vendor_id
               )',
            [$runId]
        );
        $this->copySales($runId);
    }

    public function remember(string $runId, string $vendorId): void
    {
        $this->statements->change(
            'INSERT INTO payout_run_lines (
                run_id, vendor_id, mode, sales_minor, commission_minor, tax_minor, discount_minor,
                return_minor, void_minor, draw_minor, check_paid_minor, settlement_applied_minor,
                rent_minor, rent_charged_minor, rent_brought_forward_minor, rent_paid_since_minor,
                adjustment_minor, brought_forward_minor, carried_payable_minor, carried_rent_minor,
                settled_minor, check_minor, owes_minor, net_minor
             )
             SELECT run_id, vendor_id, mode, sales_minor, commission_minor, tax_minor, discount_minor,
                    return_minor, void_minor, draw_minor, check_paid_minor, settlement_applied_minor,
                    rent_minor, rent_charged_minor, rent_brought_forward_minor, rent_paid_since_minor,
                    adjustment_minor, brought_forward_minor, carried_payable_minor, carried_rent_minor,
                    settled_minor, check_minor, owes_minor, net_minor
             FROM payout_pack
             WHERE run_id = ?::uuid AND vendor_id = ?::uuid
               AND NOT EXISTS (
                   SELECT 1 FROM payout_run_lines AS existing
                   WHERE existing.run_id = payout_pack.run_id AND existing.vendor_id = payout_pack.vendor_id
               )',
            [$runId, $vendorId]
        );
    }

    public function vendorLine(string $runId, string $vendorId): void
    {
        $this->statements->change(
            "INSERT INTO payout_run_lines (run_id, vendor_id, mode)
             SELECT ?::uuid, ?::uuid, 'pay'
             WHERE NOT EXISTS (
                 SELECT 1 FROM payout_run_lines WHERE run_id = ?::uuid AND vendor_id = ?::uuid
             )",
            [$runId, $vendorId, $runId, $vendorId]
        );
    }

    public function link(string $runId, string $journalId, string $role): void
    {
        $this->statements->change(
            'INSERT INTO payout_run_journals (run_id, journal_id, role) VALUES (?::uuid, ?::uuid, ?)',
            [$runId, $journalId, $role]
        );
    }

    public function attachSettlement(string $runId, string $vendorId, string $journalId): void
    {
        $this->statements->change(
            'UPDATE payout_run_lines SET settlement_journal_id = ?::uuid
             WHERE run_id = ?::uuid AND vendor_id = ?::uuid',
            [$journalId, $runId, $vendorId]
        );
    }

    public function attachDisbursement(string $runId, string $vendorId, string $journalId, ?string $checkNumber): void
    {
        $this->statements->change(
            'UPDATE payout_run_lines
             SET disbursement_journal_id = ?::uuid, check_number = ?
             WHERE run_id = ?::uuid AND vendor_id = ?::uuid',
            [$journalId, $checkNumber, $runId, $vendorId]
        );
    }

    /**
     * @return list<array{vendor_id: string, check_minor: int, mode: string}>
     */
    public function checks(string $runId): array
    {
        $rows = $this->statements->bound(
            "SELECT vendor_id, check_minor, mode FROM payout_run_lines
             WHERE run_id = ?::uuid AND mode = 'pay' AND check_minor > 0
             ORDER BY vendor_id",
            [$runId]
        );
        $checks = [];
        foreach ($rows as $row) {
            $checks[] = [
                'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
                'check_minor' => RowValue::integer($row['check_minor'] ?? null),
                'mode' => RowValue::string($row['mode'] ?? null),
            ];
        }

        return $checks;
    }

    public function mark(string $runId, string $status): void
    {
        if ($status === 'settled') {
            $this->statements->change(
                "UPDATE payout_runs SET status = 'settled', settled_at = clock_timestamp()
                 WHERE id = ?::uuid AND status = 'draft'",
                [$runId]
            );

            return;
        }
        if ($status === 'paid') {
            $this->statements->change(
                "UPDATE payout_runs SET status = 'paid', paid_at = clock_timestamp()
                 WHERE id = ?::uuid AND status = 'settled'",
                [$runId]
            );

            return;
        }
        throw new DatabaseRefusal('a payout run status cannot skip');
    }

    private function copySales(string $runId): void
    {
        $this->statements->change(
            'INSERT INTO payout_run_sales (
                run_id, vendor_id, journal_id, line_no, item_id, sold_on, item_code, description,
                price_minor, commission_minor, tax_minor, discount_minor, quantity
             )
             SELECT run_id, vendor_id, journal_id, line_no, item_id, sold_on, item_code, description,
                    price_minor, commission_minor, tax_minor, discount_minor, quantity
             FROM payout_pack_sales
             WHERE run_id = ?::uuid
               AND NOT EXISTS (
                   SELECT 1 FROM payout_run_sales AS existing
                   WHERE existing.run_id = payout_pack_sales.run_id
                     AND existing.journal_id = payout_pack_sales.journal_id
                     AND existing.line_no = payout_pack_sales.line_no
               )',
            [$runId]
        );
    }
}
