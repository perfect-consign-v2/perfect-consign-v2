<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Party\BoothListStore;
use PerfectConsign\Feature\Party\BoothRoster;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class DashboardStore
{
    /** @var array<string, string> */
    private const TENDERS = [
        'cash' => 'Cash',
        'card' => 'Card',
        'checks' => 'Check',
        'gift_certificate' => 'Gift certificate',
        'gift_card' => 'Gift card',
        'store_credit' => 'Store credit',
    ];

    /** @var array<string, string> */
    private const STANDING = [
        'occupied' => 'badge-success',
        'available' => 'badge-info',
        'incoming' => 'badge-accent',
        'ending' => 'badge-warning',
    ];

    public function __construct(
        private readonly Statements $statements,
        private readonly MallSettings $mall,
        private readonly PartyStore $parties,
        private readonly BoothRoster $roster,
        private readonly BoothListStore $lists,
    ) {
    }

    /**
     * @return array{
     *   sales: array{amount: string, delta: ?array{direction: string, text: string}},
     *   payables: array{amount: string, caption: string},
     *   trend: list<array{day: string, title: string, height: int, today: bool, amount: string}>,
     *   today_badge: string,
     *   sales_list: list<array{id: string, number: string, time: string, date: string, tenders: list<string>, total: string}>,
     *   occupancy: array{percent: int, caption: string, rows: list<array{label: string, count: int, badge: string}>},
     *   shelf: array{value: string, unsold: int}
     * }
     */
    public function picture(string $today): array
    {
        $days = $this->days($today);
        $totals = $this->salesByDay($days[0], $today);
        $todayMinor = $totals[$today] ?? 0;
        $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');

        return [
            'sales' => [
                'amount' => $this->money($todayMinor),
                'delta' => $this->delta($todayMinor, $totals[$yesterday] ?? 0),
            ],
            'payables' => $this->payables(),
            'trend' => $this->trend($days, $totals, $today),
            'today_badge' => $this->money($todayMinor) . ' today',
            'sales_list' => $this->recent(),
            'occupancy' => $this->occupancy($today),
            'shelf' => $this->shelf(),
        ];
    }

    /**
     * @return list<string>
     */
    private function days(string $today): array
    {
        $end = new DateTimeImmutable($today);
        $days = [];
        for ($back = 13; $back >= 0; $back--) {
            $days[] = $end->modify('-' . $back . ' days')->format('Y-m-d');
        }

        return $days;
    }

    /**
     * @return array<string, int>
     */
    private function salesByDay(string $from, string $to): array
    {
        $roles = array_keys(self::TENDERS);
        $marks = implode(', ', array_fill(0, count($roles), '?'));
        $rows = $this->statements->bound(
            'SELECT journal.posting_date::text AS day,
                    COALESCE(sum(line.debit_minor - line.credit_minor), 0) AS net
             FROM journals AS journal
             JOIN journal_lines AS line ON line.journal_id = journal.id
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE journal.source_type = ?
               AND journal.posting_date BETWEEN ?::date AND ?::date
               AND role.role IN (' . $marks . ')
             GROUP BY journal.posting_date',
            ['sale', $from, $to, ...$roles]
        );
        $totals = [];
        foreach ($rows as $row) {
            $totals[RowValue::string($row['day'] ?? null)] = RowValue::integer($row['net'] ?? 0);
        }

        return $totals;
    }

    /**
     * @param list<string> $days
     * @param array<string, int> $totals
     * @return list<array{day: string, title: string, height: int, today: bool, amount: string}>
     */
    private function trend(array $days, array $totals, string $today): array
    {
        $peak = 0;
        foreach ($days as $day) {
            $peak = max($peak, $totals[$day] ?? 0);
        }
        $bars = [];
        foreach ($days as $day) {
            $minor = $totals[$day] ?? 0;
            $date = new DateTimeImmutable($day);
            $bars[] = [
                'day' => $date->format('j'),
                'title' => $date->format('m-d-Y') . ': ' . $this->money($minor),
                'height' => $peak === 0 ? 0 : (int) round($minor * 100 / $peak),
                'today' => $day === $today,
                'amount' => $this->money($minor),
            ];
        }

        return $bars;
    }

    /**
     * @return array{amount: string, caption: string}
     */
    private function payables(): array
    {
        $rows = $this->statements->bound(
            "SELECT line.subledger_ref
             FROM journal_lines AS line
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = ?
               AND line.subledger_type = ?
             GROUP BY line.subledger_ref
             HAVING sum(line.credit_minor - line.debit_minor) > 0",
            ['vendor_payable', 'vendor']
        );
        $open = $this->statements->bound(
            "SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) AS open
             FROM journal_lines AS line
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = ?
               AND line.subledger_type = ?",
            ['vendor_payable', 'vendor']
        );
        $count = count($rows);
        $vendors = $count === 1 ? '1 vendor' : $count . ' vendors';

        return [
            'amount' => $this->money(RowValue::integer($open[0]['open'] ?? 0)),
            'caption' => 'Owed across ' . $vendors,
        ];
    }

    /**
     * @return list<array{id: string, number: string, time: string, date: string, tenders: list<string>, total: string}>
     */
    public function sales(string $day, int $limit): array
    {
        if ($day !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return [];
        }
        $cap = $limit === 5 ? 5 : 200;
        $roles = array_keys(self::TENDERS);
        $marks = implode(', ', array_fill(0, count($roles), '?'));
        $daySql = $day === '' ? '' : ' AND journal.posting_date = ?::date';
        $params = ['sale'];
        if ($day !== '') {
            $params[] = $day;
        }
        $rows = $this->statements->bound(
            'SELECT journal.id::text AS id, journal.journal_no::text AS journal_no, journal.occurred_at,
                    COALESCE(sum(line.debit_minor - line.credit_minor), 0) AS total,
                    COALESCE(string_agg(DISTINCT role.role, \',\' ORDER BY role.role), \'\') AS tenders
             FROM journals AS journal
             JOIN journal_lines AS line ON line.journal_id = journal.id
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE journal.source_type = ?
               AND journal.is_reversal = false
               AND NOT EXISTS (
                   SELECT 1 FROM journals AS reversal
                   WHERE reversal.reverses_journal_id = journal.id
               )' . $daySql . '
               AND role.role IN (' . $marks . ')
             GROUP BY journal.id
             ORDER BY journal.occurred_at DESC
             LIMIT ' . $cap,
            [...$params, ...$roles]
        );
        $sales = [];
        foreach ($rows as $row) {
            $sales[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'number' => RowValue::string($row['journal_no'] ?? null),
                'time' => $this->clock($row['occurred_at'] ?? null),
                'date' => $this->clock($row['occurred_at'] ?? null, 'm-d-Y g:i A'),
                'tenders' => $this->tenderLabels(RowValue::string($row['tenders'] ?? null)),
                'total' => $this->money(RowValue::integer($row['total'] ?? 0)),
            ];
        }

        return $sales;
    }

    /**
     * @return list<array{id: string, number: string, time: string, date: string, tenders: list<string>, total: string}>
     */
    private function recent(): array
    {
        return $this->sales('', 5);
    }

    /**
     * @return list<string>
     */
    private function tenderLabels(string $roles): array
    {
        if ($roles === '') {
            return [];
        }
        $labels = [];
        foreach (explode(',', $roles) as $role) {
            $labels[] = self::TENDERS[$role] ?? $role;
        }

        return $labels;
    }

    /**
     * @return array{percent: int, caption: string, rows: list<array{label: string, count: int, badge: string}>}
     */
    private function occupancy(string $today): array
    {
        $facts = $this->lists->facts();
        $rows = $this->roster->assemble(
            $this->parties->booths(),
            $facts['stays'],
            $this->lists->rates(),
            $facts['open_rent'],
            $facts['blocked'],
            $today,
            $this->mall->boothSize()['width'],
            $this->mall->boothSize()['depth'],
        );
        $counts = ['occupied' => 0, 'available' => 0, 'ending' => 0, 'incoming' => 0];
        foreach ($rows as $row) {
            $status = $row['status'];
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        $total = count($rows);
        $standing = [];
        foreach ($counts as $status => $count) {
            $standing[] = [
                'label' => ucfirst($status),
                'count' => $count,
                'badge' => self::STANDING[$status],
            ];
        }

        return [
            'percent' => $total === 0 ? 0 : (int) round($counts['occupied'] * 100 / $total),
            'caption' => $counts['occupied'] . ' / ' . $total . ' booths',
            'rows' => $standing,
        ];
    }

    /**
     * @return array{value: string, unsold: int}
     */
    private function shelf(): array
    {
        $value = $this->statements->rows(
            'SELECT COALESCE(sum(price_minor * quantity), 0) AS total FROM items WHERE quantity >= 1'
        );
        $unsold = $this->statements->rows(
            'SELECT count(*)::int AS total FROM items WHERE quantity = 0'
        );

        return [
            'value' => $this->money(RowValue::integer($value[0]['total'] ?? 0)),
            'unsold' => RowValue::integer($unsold[0]['total'] ?? 0),
        ];
    }

    /**
     * @return array{direction: string, text: string}|null
     */
    private function delta(int $today, int $yesterday): ?array
    {
        if ($yesterday === 0) {
            return null;
        }
        $change = ($today - $yesterday) * 100 / $yesterday;

        return [
            'direction' => $change < 0 ? 'down' : 'up',
            'text' => ($change < 0 ? '▼ ' : '▲ ') . number_format(abs($change), 1) . '% vs yesterday',
        ];
    }

    private function money(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';

        return $sign . '$' . number_format(abs($minor) / 100, 2);
    }

    private function clock(mixed $value, string $format = 'g:i A'): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        $time = date_create($value);
        if ($time === false) {
            return '';
        }
        $time->setTimezone(new DateTimeZone($this->mall->timeZone()));

        return $time->format($format);
    }
}
