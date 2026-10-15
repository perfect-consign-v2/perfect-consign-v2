<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

final class RentBill
{
    /**
     * Open charges, the payments already applied to them, and a last line when sales cover part of the balance.
     * A late fee can be waived only while nothing has been paid against it.
     *
     * @param list<array{journal_id: string, source: string, date: string, booth: string, debit: int, credit: int}> $moves
     * @return list<array{label: string, date: string, amount: int, journal_id: string}>
     */
    public function explain(array $moves, int $owed): array
    {
        $settled = $this->settle($moves);
        $lines = $this->present($this->rows($settled['charges'], $settled['loose']));
        $sum = $this->total($lines);
        if ($sum === $owed) {
            return $lines;
        }
        $lines[] = [
            'label' => $sum > $owed ? 'From what they earned' : 'Rent',
            'date' => '',
            'amount' => $owed - $sum,
            'journal_id' => '',
        ];

        return $lines;
    }

    /**
     * @param list<array{journal_id: string, source: string, date: string, booth: string, debit: int, credit: int}> $moves
     * @return array{
     *     charges: list<array{journal_id: string, source: string, date: string, booth: string, debit: int, left: int, credits: list<array{source: string, date: string, amount: int}>}>,
     *     loose: list<array{label: string, date: string, amount: int, journal_id: string, booth: string}>
     * }
     */
    private function settle(array $moves): array
    {
        $charges = [];
        $loose = [];
        foreach ($moves as $move) {
            if ($move['debit'] > 0) {
                $charges[] = $this->charge($move);
                continue;
            }
            if ($move['credit'] < 1) {
                continue;
            }
            $left = $this->take($charges, $move['credit'], 'rent', $move);
            $left = $this->take($charges, $left, 'late', $move);
            if ($left < 1) {
                continue;
            }
            $loose[] = [
                'label' => $this->label($move['source']),
                'date' => $move['date'],
                'amount' => -$left,
                'journal_id' => '',
                'booth' => $move['booth'],
            ];
        }

        return ['charges' => $charges, 'loose' => $loose];
    }

    /**
     * @param array{journal_id: string, source: string, date: string, booth: string, debit: int, credit: int} $move
     * @return array{journal_id: string, source: string, date: string, booth: string, debit: int, left: int, credits: list<array{source: string, date: string, amount: int}>}
     */
    private function charge(array $move): array
    {
        return [
            'journal_id' => $move['journal_id'],
            'source' => $move['source'],
            'date' => $move['date'],
            'booth' => $move['booth'],
            'debit' => $move['debit'],
            'left' => $move['debit'],
            'credits' => [],
        ];
    }

    /**
     * Payments come off rent first, then late fees. Only charges already on the bill can take a payment.
     *
     * @param list<array{journal_id: string, source: string, date: string, booth: string, debit: int, left: int, credits: list<array{source: string, date: string, amount: int}>}> $charges
     * @param array{journal_id: string, source: string, date: string, booth: string, debit: int, credit: int} $move
     */
    private function take(array &$charges, int $amount, string $kind, array $move): int
    {
        $left = $amount;
        foreach ($charges as $index => $charge) {
            if ($left < 1) {
                return 0;
            }
            if (!$this->accepts($charge['source'], $kind) || $charge['left'] < 1) {
                continue;
            }
            $used = min($charge['left'], $left);
            $charges[$index]['left'] -= $used;
            $charges[$index]['credits'][] = [
                'source' => $move['source'],
                'date' => $move['date'],
                'amount' => $used,
            ];
            $left -= $used;
        }

        return $left;
    }

    private function accepts(string $source, string $kind): bool
    {
        $fee = $source === 'late_fee';

        return $kind === 'late' ? $fee : !$fee;
    }

    /**
     * @param list<array{journal_id: string, source: string, date: string, booth: string, debit: int, left: int, credits: list<array{source: string, date: string, amount: int}>}> $charges
     * @param list<array{label: string, date: string, amount: int, journal_id: string, booth: string}> $loose
     * @return list<array{label: string, date: string, amount: int, journal_id: string, booth: string}>
     */
    private function rows(array $charges, array $loose): array
    {
        $lines = [];
        foreach ($charges as $charge) {
            if ($charge['left'] < 1) {
                continue;
            }
            array_push($lines, ...$this->chargeLines($charge));
        }

        return array_merge($lines, $loose);
    }

    /**
     * @param array{journal_id: string, source: string, date: string, booth: string, debit: int, left: int, credits: list<array{source: string, date: string, amount: int}>} $charge
     * @return list<array{label: string, date: string, amount: int, journal_id: string, booth: string}>
     */
    private function chargeLines(array $charge): array
    {
        if ($charge['credits'] === []) {
            return [[
                'label' => $this->label($charge['source']),
                'date' => $charge['date'],
                'amount' => $charge['left'],
                'journal_id' => $charge['source'] === 'late_fee' ? $charge['journal_id'] : '',
                'booth' => $charge['booth'],
            ]];
        }
        $lines = [[
            'label' => $this->label($charge['source']),
            'date' => $charge['date'],
            'amount' => $charge['debit'],
            'journal_id' => '',
            'booth' => $charge['booth'],
        ]];
        foreach ($charge['credits'] as $credit) {
            $lines[] = [
                'label' => $this->label($credit['source']),
                'date' => $credit['date'],
                'amount' => -$credit['amount'],
                'journal_id' => '',
                'booth' => $charge['booth'],
            ];
        }

        return $lines;
    }

    /**
     * @param list<array{label: string, date: string, amount: int, journal_id: string, booth: string}> $lines
     * @return list<array{label: string, date: string, amount: int, journal_id: string}>
     */
    private function present(array $lines): array
    {
        $booths = [];
        foreach ($lines as $line) {
            if ($line['booth'] !== '') {
                $booths[$line['booth']] = true;
            }
        }
        $shown = [];
        foreach ($lines as $line) {
            $label = $line['label'];
            if (count($booths) > 1 && $line['booth'] !== '') {
                $label .= ' ' . $line['booth'];
            }
            $shown[] = [
                'label' => $label,
                'date' => $line['date'],
                'amount' => $line['amount'],
                'journal_id' => $line['journal_id'],
            ];
        }

        return $shown;
    }

    /**
     * @param list<array{label: string, date: string, amount: int, journal_id: string}> $lines
     */
    private function total(array $lines): int
    {
        $sum = 0;
        foreach ($lines as $line) {
            $sum += $line['amount'];
        }

        return $sum;
    }

    private function label(string $source): string
    {
        if ($source === 'rent_payment') {
            return 'Paid';
        }
        if ($source === 'settlement') {
            return 'Taken from their sales';
        }
        if ($source === 'late_fee') {
            return 'Late fee';
        }

        return 'Rent';
    }
}
