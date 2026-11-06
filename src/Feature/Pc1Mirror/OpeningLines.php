<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PerfectConsign\Ledger\PostedLines;

final class OpeningLines
{
    /**
     * @param array{
     *     vendors: array<string, string>,
     *     booths: array<string, string>,
     *     stored_values: array<string, string>
     * } $idMap
     * @param array<string, mixed> $balances
     * @param list<array<string, mixed>> $stored
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    public function build(MirrorMall $mall, array $idMap, array $balances, array $stored): array
    {
        $lines = [];
        $debit = 0;
        $credit = 0;
        $this->appendPayables($mall, $idMap['vendors'], $balances, $lines, $credit);
        $this->appendReceivables($mall, $idMap['vendors'], $balances, $lines, $debit);
        $this->appendRent($mall, $idMap['booths'], $balances, $lines, $debit);
        $this->appendTaxCash($mall, $balances, $lines, $debit, $credit);
        $this->appendStored($mall, $idMap['stored_values'], $stored, $lines, $credit);
        $this->appendPlug($mall, $debit, $credit, $lines);
        if (count($lines) < 2) {
            throw new MirrorException('opening needs at least two balance lines');
        }

        return $lines;
    }

    /**
     * @param array<string, string> $vendors
     * @param array<string, mixed> $balances
     * @param list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}> $lines
     */
    private function appendPayables(MirrorMall $mall, array $vendors, array $balances, array &$lines, int &$credit): void
    {
        foreach ($this->rows($balances['vendor_payable'] ?? null) as $row) {
            $vendorId = $vendors[MirrorFields::text($row['pc1_id'] ?? null)] ?? null;
            $cents = MirrorFields::cents($row['cents'] ?? 0);
            if ($vendorId === null || $cents < 1) {
                continue;
            }
            $lines[] = PostedLines::entry($mall->ledger->roleAccount(AccountMap::VENDOR_PAYABLE), 0, $cents, 'vendor', $vendorId);
            $credit += $cents;
        }
    }

    /**
     * @param array<string, string> $vendors
     * @param array<string, mixed> $balances
     * @param list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}> $lines
     */
    private function appendReceivables(MirrorMall $mall, array $vendors, array $balances, array &$lines, int &$debit): void
    {
        foreach ($this->rows($balances['vendor_receivable'] ?? null) as $row) {
            $vendorId = $vendors[MirrorFields::text($row['pc1_id'] ?? null)] ?? null;
            $cents = MirrorFields::cents($row['cents'] ?? 0);
            if ($vendorId === null || $cents < 1) {
                continue;
            }
            $lines[] = PostedLines::entry($mall->ledger->roleAccount(AccountMap::VENDOR_RECEIVABLE), $cents, 0, 'vendor', $vendorId);
            $debit += $cents;
        }
    }

    /**
     * @param array<string, string> $booths
     * @param array<string, mixed> $balances
     * @param list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}> $lines
     */
    private function appendRent(MirrorMall $mall, array $booths, array $balances, array &$lines, int &$debit): void
    {
        $byBooth = [];
        foreach ($this->rows($balances['booth_rent'] ?? null) as $row) {
            $pc1Booth = MirrorFields::text($row['pc1_booth_id'] ?? null);
            $boothId = $booths[$pc1Booth] ?? null;
            $cents = MirrorFields::cents($row['cents'] ?? 0);
            if ($boothId === null || $cents < 1) {
                continue;
            }
            $byBooth[$boothId] = ($byBooth[$boothId] ?? 0) + $cents;
        }
        foreach ($byBooth as $boothId => $cents) {
            $lines[] = PostedLines::entry($mall->ledger->roleAccount(AccountMap::BOOTH_RENT), $cents, 0, 'booth', $boothId);
            $debit += $cents;
        }
    }

    /**
     * @param array<string, mixed> $balances
     * @param list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}> $lines
     */
    private function appendTaxCash(MirrorMall $mall, array $balances, array &$lines, int &$debit, int &$credit): void
    {
        $tax = MirrorFields::cents($balances['sales_tax_payable_cents'] ?? 0);
        if ($tax > 0) {
            $lines[] = PostedLines::entry($mall->ledger->roleAccount(AccountMap::SALES_TAX), 0, $tax, null, null);
            $credit += $tax;
        }
        $cash = MirrorFields::cents($balances['cash_cents'] ?? 0);
        if ($cash > 0) {
            $lines[] = PostedLines::entry($mall->ledger->roleAccount(AccountMap::CASH), $cash, 0, null, null);
            $debit += $cash;
        }
    }

    /**
     * @param array<string, string> $storedMap
     * @param list<array<string, mixed>> $stored
     * @param list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}> $lines
     */
    private function appendStored(MirrorMall $mall, array $storedMap, array $stored, array &$lines, int &$credit): void
    {
        foreach ($stored as $row) {
            $pc1 = MirrorFields::text($row['pc1_id'] ?? null);
            $kind = MirrorFields::text($row['kind'] ?? null);
            $cents = MirrorFields::cents($row['balance_minor'] ?? 0);
            $storedId = $storedMap[$pc1] ?? null;
            $role = match ($kind) {
                'gift_certificate' => AccountMap::GIFT_CERTIFICATE,
                'gift_card' => AccountMap::GIFT_CARD,
                'store_credit' => AccountMap::STORE_CREDIT,
                default => null,
            };
            if ($storedId === null || $cents < 1 || $role === null) {
                continue;
            }
            $lines[] = PostedLines::entry($mall->ledger->roleAccount($role), 0, $cents, $kind, $storedId);
            $credit += $cents;
        }
    }

    /**
     * @param list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}> $lines
     */
    private function appendPlug(MirrorMall $mall, int $debit, int $credit, array &$lines): void
    {
        $plug = $credit - $debit;
        if ($plug > 0) {
            $lines[] = PostedLines::entry($mall->ledger->roleAccount(AccountMap::RETAINED), $plug, 0, null, null);
        } elseif ($plug < 0) {
            $lines[] = PostedLines::entry($mall->ledger->roleAccount(AccountMap::RETAINED), 0, -$plug, null, null);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $out[] = MirrorFields::stringKeyed($row);
            }
        }

        return $out;
    }
}
