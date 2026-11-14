<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PDO;

/**
 * Open booth rent AR by booth, aligned with PC1 rent-remainder true_open.
 */
final class Pc1RentOpen
{
    public function __construct(
        private readonly Pc1BillingCalendar $calendar = new Pc1BillingCalendar(),
        private readonly Pc1RentPayments $payments = new Pc1RentPayments(),
    ) {
    }

    /**
     * @return list<array{pc1_booth_id: string, pc1_consignor_id: string, cents: int}>
     */
    public function asOfToday(PDO $pdo, int $cycleDay): array
    {
        return $this->byBooth($pdo, $cycleDay, new \DateTimeImmutable('today'));
    }

    /**
     * @return list<array{pc1_booth_id: string, pc1_consignor_id: string, cents: int}>
     */
    public function byBooth(PDO $pdo, int $cycleDay, \DateTimeImmutable $today): array
    {
        $cycleDay = max(1, min(31, $cycleDay));
        $assignments = $pdo->query(
            'SELECT a.consignor_booth_assignment_id::text AS assignment_id,
                    a.consignor_id::text AS consignor_id,
                    a.booth_id::text AS booth_id,
                    a.started_at::text AS started_at,
                    a.ended_at::text AS ended_at,
                    a.monthly_rent::text AS monthly_rent
             FROM consignor_booth_assignments a
             ORDER BY a.started_at'
        );
        $assignmentRows = $assignments !== false ? $assignments->fetchAll(PDO::FETCH_ASSOC) : [];
        $loaded = $this->payments->load($pdo);
        $openByBooth = [];
        foreach ($assignmentRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $this->accumulateAssignment($row, $cycleDay, $today, $loaded['tagged'], $loaded['untagged'], $openByBooth);
        }

        return array_values($openByBooth);
    }

    /**
     * @param array<mixed> $row
     * @param array<string, list<array{period_start: string, period_end: string, amount_cents: int}>> $tagged
     * @param array<string, list<array{period_start: string, period_end: string, amount_cents: int}>> $untagged
     * @param array<string, array{pc1_booth_id: string, pc1_consignor_id: string, cents: int}> $openByBooth
     */
    private function accumulateAssignment(
        array $row,
        int $cycleDay,
        \DateTimeImmutable $today,
        array $tagged,
        array $untagged,
        array &$openByBooth,
    ): void {
        $boothId = MirrorFields::text($row['booth_id'] ?? null);
        $consignorId = MirrorFields::text($row['consignor_id'] ?? null);
        $assignmentId = MirrorFields::text($row['assignment_id'] ?? null);
        $monthly = Pc1Money::dollarsToCents(MirrorFields::text($row['monthly_rent'] ?? null, '0'));
        if ($boothId === '' || $consignorId === '' || $assignmentId === '' || $monthly < 1) {
            return;
        }
        $started = new \DateTimeImmutable(MirrorFields::text($row['started_at'] ?? null));
        $endedRaw = MirrorFields::text($row['ended_at'] ?? null);
        $ended = $endedRaw !== '' ? new \DateTimeImmutable($endedRaw) : null;
        $open = $this->openCents($started, $ended, $today, $cycleDay, $monthly, $assignmentId, $consignorId, $tagged, $untagged);
        if ($open < 1) {
            return;
        }
        $key = $boothId . '|' . $consignorId;
        if (!isset($openByBooth[$key])) {
            $openByBooth[$key] = [
                'pc1_booth_id' => $boothId,
                'pc1_consignor_id' => $consignorId,
                'cents' => 0,
            ];
        }
        $openByBooth[$key]['cents'] += $open;
    }

    /**
     * @param array<string, list<array{period_start: string, period_end: string, amount_cents: int}>> $tagged
     * @param array<string, list<array{period_start: string, period_end: string, amount_cents: int}>> $untagged
     */
    private function openCents(
        \DateTimeImmutable $started,
        ?\DateTimeImmutable $ended,
        \DateTimeImmutable $today,
        int $cycleDay,
        int $monthly,
        string $assignmentId,
        string $consignorId,
        array $tagged,
        array $untagged,
    ): int {
        $through = $ended !== null && $ended < $today ? $ended : $today;
        $cursor = $this->calendar->startOfBillingMonthContaining($started, $cycleDay);
        $guard = 0;
        $open = 0;
        while ($guard++ < 240 && $cursor <= $through) {
            $monthEnd = $this->calendar->endOfBillingMonthContaining($cursor, $cycleDay);
            $segmentEnd = $ended !== null && $ended < $monthEnd ? $ended : $monthEnd;
            if ($segmentEnd < $started) {
                break;
            }
            $segmentStart = $cursor < $started ? $started : $cursor;
            $assessed = $this->prorate($segmentStart, $segmentEnd, $monthEnd, $monthly, $started, $cycleDay);
            $periodStart = $segmentStart->format('Y-m-d');
            $periodEnd = $segmentEnd->format('Y-m-d');
            $open += max(
                0,
                $assessed
                - $this->overlap($tagged[$assignmentId] ?? [], $periodStart, $periodEnd)
                - $this->overlap($untagged[$consignorId] ?? [], $periodStart, $periodEnd)
            );
            $cursor = $monthEnd->modify('+1 day');
        }

        return $open;
    }

    private function prorate(
        \DateTimeImmutable $segmentStart,
        \DateTimeImmutable $segmentEnd,
        \DateTimeImmutable $billingEnd,
        int $monthlyRentCents,
        \DateTimeImmutable $assignmentStart,
        int $cycleDay,
    ): int {
        $billingStart = $this->calendar->startOfBillingMonthContaining($segmentStart, $cycleDay);
        if ($segmentStart <= $billingStart && $segmentEnd >= $billingEnd) {
            return $monthlyRentCents;
        }
        $daysInMonth = (int) $billingStart->diff($billingEnd->modify('+1 day'))->format('%a');
        if ($daysInMonth < 1) {
            return 0;
        }
        $chargeFrom = $segmentStart > $assignmentStart ? $segmentStart : $assignmentStart;
        if ($chargeFrom > $segmentEnd) {
            return 0;
        }
        $days = (int) $chargeFrom->diff($segmentEnd->modify('+1 day'))->format('%a');

        return (int) round($monthlyRentCents * ($days / $daysInMonth));
    }

    /**
     * @param list<array{period_start: string, period_end: string, amount_cents: int}> $payments
     */
    private function overlap(array $payments, string $periodStart, string $periodEnd): int
    {
        $total = 0;
        foreach ($payments as $payment) {
            if ($payment['period_start'] <= $periodEnd && $payment['period_end'] >= $periodStart) {
                $total += max(0, $payment['amount_cents']);
            }
        }

        return $total;
    }
}
