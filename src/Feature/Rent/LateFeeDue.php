<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use DateTimeImmutable;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * An unpaid rent period can take a late fee each time grace passes.
 * The first fee in that period uses the first amount. Each later fee uses the next amount.
 * Grace of 0 posts a fee each day through the end of the period.
 */
final class LateFeeDue
{
    public function __construct(
        private readonly Statements $statements,
        private readonly MallPayoutSettings $settings,
        private readonly LateFee $fees,
    ) {
    }

    public function waive(string $journalId, string $date, string $note = ''): string
    {
        return $this->fees->waive($journalId, $date, $note);
    }

    public function apply(string $today): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
            return;
        }
        $policy = $this->settings->lateFee();
        if (!$this->settings->rentalsEnabled() || !$policy['enabled']) {
            return;
        }
        $first = $policy['first'];
        $next = $policy['next'];
        if ($first < 1 || $next < 1) {
            return;
        }
        $effective = $policy['effective'] !== '' ? $policy['effective'] : $today;
        foreach ($this->due($today, $policy['grace'], $effective) as $fee) {
            $amount = $fee['ordinal'] === 1 ? $first : $next;
            $this->fees->post($fee['booth'], $fee['period'], $fee['date'], $amount, $fee['ordinal']);
        }
    }

    /**
     * @return list<array{booth: string, period: string, ordinal: int, date: string}>
     */
    private function due(string $today, int $grace, string $effective): array
    {
        $posted = $this->postedKeys();
        $due = [];
        foreach ($this->byBooth() as $booth => $book) {
            foreach ($this->unpaid($book['charges'], $book['credit']) as $period) {
                if ($period['left'] < 1 || $period['start'] < $effective) {
                    continue;
                }
                foreach ($this->slots($period['start'], $today, $grace) as $index => $date) {
                    $ordinal = $index + 1;
                    $key = 'late-fee:' . $booth . ':' . $period['period'] . ':' . $ordinal;
                    if (isset($posted[$key])) {
                        continue;
                    }
                    $due[] = ['booth' => $booth, 'period' => $period['period'], 'ordinal' => $ordinal, 'date' => $date];
                }
            }
        }

        return $due;
    }

    /**
     * @return list<string>
     */
    private function slots(string $start, string $today, int $grace): array
    {
        $end = $this->periodEnd($start);
        $step = $grace < 1 ? 1 : $grace;
        $date = $this->lateOn($start, $grace);
        $slots = [];
        while ($date <= $today && $date <= $end) {
            $slots[] = $date;
            $date = (new DateTimeImmutable($date))->modify('+' . $step . ' days')->format('Y-m-d');
        }

        return $slots;
    }

    private function periodEnd(string $start): string
    {
        return (new DateTimeImmutable($start))->modify('+1 month')->modify('-1 day')->format('Y-m-d');
    }

    /**
     * @param list<array{period: string, start: string, left: int}> $charges
     * @return list<array{period: string, start: string, left: int}>
     */
    private function unpaid(array $charges, int $credit): array
    {
        $left = $credit;
        foreach ($charges as $index => $charge) {
            $used = min($charge['left'], $left);
            $charges[$index]['left'] -= $used;
            $left -= $used;
        }

        return $charges;
    }

    /**
     * @return array<string, array{charges: list<array{period: string, start: string, left: int}>, credit: int}>
     */
    private function byBooth(): array
    {
        $books = [];
        foreach ($this->moves() as $move) {
            $booth = RowValue::optionalString($move['booth_id'] ?? null);
            if ($booth === null) {
                continue;
            }
            if (!isset($books[$booth])) {
                $books[$booth] = ['charges' => [], 'credit' => 0];
            }
            $books[$booth]['credit'] += RowValue::integer($move['credit_minor'] ?? null);
            $period = $this->rentPeriod(RowValue::string($move['posting_key'] ?? null));
            if ($period === null || RowValue::string($move['source_type'] ?? null) !== 'rent_charge') {
                continue;
            }
            if (RowValue::integer($move['debit_minor'] ?? null) < 1) {
                continue;
            }
            $books[$booth]['charges'][] = [
                'period' => $period['period'],
                'start' => $period['start'],
                'left' => RowValue::integer($move['debit_minor'] ?? null),
            ];
        }

        return $books;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function moves(): array
    {
        return $this->statements->rows(
            "SELECT posted.posting_key, posted.source_type, line.subledger_ref AS booth_id,
                    line.debit_minor, line.credit_minor
             FROM journals AS posted
             JOIN journal_lines AS line ON line.journal_id = posted.id
             JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'booth_rent_receivable'
             WHERE line.subledger_type = 'booth'
               AND NOT posted.is_reversal
               AND NOT EXISTS (
                   SELECT 1 FROM journals AS reversal WHERE reversal.reverses_journal_id = posted.id
               )
             ORDER BY posted.posting_date, posted.posting_key"
        );
    }

    /**
     * @return array<string, true>
     */
    private function postedKeys(): array
    {
        $keys = [];
        foreach ($this->statements->rows(
            "SELECT posting_key FROM journals WHERE source_type = 'late_fee' AND posting_key LIKE 'late-fee:%'"
        ) as $row) {
            $key = RowValue::optionalString($row['posting_key'] ?? null);
            if ($key !== null) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * @return array{period: string, start: string}|null
     */
    private function rentPeriod(string $key): ?array
    {
        if (!str_starts_with($key, 'rent-charge:')) {
            return null;
        }
        $colon = strpos(substr($key, strlen('rent-charge:')), ':');
        if ($colon === false) {
            return null;
        }
        $suffix = substr($key, strlen('rent-charge:') + $colon + 1);
        if (preg_match('/^\d{4}-\d{2}$/', $suffix) === 1) {
            return ['period' => $suffix, 'start' => $suffix . '-01'];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $suffix) === 1) {
            return ['period' => $suffix, 'start' => $suffix];
        }

        return null;
    }

    private function lateOn(string $start, int $grace): string
    {
        if ($grace === 0) {
            return $start;
        }

        return (new DateTimeImmutable($start))->modify('+' . $grace . ' days')->format('Y-m-d');
    }
}
