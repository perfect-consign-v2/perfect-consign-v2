<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Register;

use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class RegisterActivity
{
    /** @var list<string> */
    private const TENDER_ROLES = [
        'cash',
        'card',
        'checks',
        'gift_certificate',
        'gift_card',
        'store_credit',
    ];

    /** @var array<string, string> */
    private const CASH_LINES = [
        'sale' => 'Cash sales',
        'rent_payment' => 'Rent paid in cash',
        'gift_certificate_issue' => 'Gift certificates sold for cash',
        'gift_card_issue' => 'Gift cards sold for cash',
        'store_credit_issue' => 'Store credit sold for cash',
        'payout' => 'Cash payouts',
        'cash_drop' => 'Cash drops',
    ];

    public function __construct(
        private readonly Statements $statements,
        private readonly MallClock $clock,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function shifts(string $registerId): array
    {
        $rows = $this->statements->bound(
            'SELECT id, cashier, opened_at, closed_at, opening_cash_minor,
                    counted_cash_minor, counted_card_minor, counted_checks_minor
             FROM register_sessions
             WHERE register_id = ?::uuid
             ORDER BY opened_at DESC',
            [$registerId]
        );
        $takenBySession = $this->shiftTenders($registerId);
        $shifts = [];
        foreach ($rows as $row) {
            $closed = is_string($row['closed_at'] ?? null) && $row['closed_at'] !== '';
            $row['opened_at'] = $this->local($row['opened_at'] ?? null);
            $row['closed_at'] = $this->local($row['closed_at'] ?? null);
            if ($closed) {
                $sessionId = RowValue::string($row['id'] ?? null);
                $taken = $takenBySession[$sessionId] ?? ['cash' => 0, 'card' => 0, 'checks' => 0];
                $countedCash = RowValue::integer($row['counted_cash_minor'] ?? 0);
                $expectedCash = RowValue::integer($row['opening_cash_minor'] ?? 0) + $taken['cash'];
                $row['cash_difference'] = DenominationCount::difference($countedCash, $expectedCash);
                $row['cash_mark'] = DenominationCount::mark($countedCash, $expectedCash);
                $row['posted_card_minor'] = $taken['card'];
                $row['posted_checks_minor'] = $taken['checks'];
            }
            $shifts[] = $row;
        }

        return $shifts;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function tape(string $sessionId): ?array
    {
        $rows = $this->statements->bound(
            'SELECT session.id, session.cashier, session.opened_at, session.closed_at,
                    session.opening_counts, session.opening_cash_minor,
                    session.closing_counts, session.counted_cash_minor,
                    session.counted_card_minor, session.counted_checks_minor,
                    register.id AS register_id, register.name
             FROM register_sessions AS session
             JOIN registers AS register ON register.id = session.register_id
             WHERE session.id = ?::uuid',
            [$sessionId]
        );
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        $closed = ($row['closed_at'] ?? null) !== null;
        $drawer = $this->drawer($sessionId);
        $opening = RowValue::integer($row['opening_cash_minor'] ?? 0);

        return [
            'register_id' => RowValue::string($row['register_id'] ?? null),
            'name' => RowValue::string($row['name'] ?? null),
            'cashier' => RowValue::string($row['cashier'] ?? null),
            'opened_at' => $this->local($row['opened_at'] ?? null),
            'closed_at' => $closed ? $this->local($row['closed_at'] ?? null) : '',
            'closed' => $closed,
            'opening' => $this->grid($row['opening_counts'] ?? null),
            'closing' => $closed ? $this->grid($row['closing_counts'] ?? null) : [],
            'opening_cash' => DenominationCount::dollars($opening),
            'counted_cash' => $closed ? DenominationCount::dollars(RowValue::integer($row['counted_cash_minor'] ?? 0)) : '',
            'expected' => DenominationCount::dollars($drawer['expected']),
            'expected_minor' => $drawer['expected'],
            'counted_minor' => $closed ? RowValue::integer($row['counted_cash_minor'] ?? 0) : 0,
            'lines' => $this->cashLines($sessionId, $opening),
            'drops' => $drawer['drops'],
            'tenders' => $drawer['tenders'],
            'counted_card' => $closed ? DenominationCount::dollars(RowValue::integer($row['counted_card_minor'] ?? 0)) : '',
            'counted_checks' => $closed ? DenominationCount::dollars(RowValue::integer($row['counted_checks_minor'] ?? 0)) : '',
        ];
    }

    /**
     * @return array{expected: int, tenders: array<string, int>, drops: list<array{when: string, amount: string}>}
     */
    public function drawer(string $sessionId): array
    {
        return [
            'expected' => $this->expectedCash($sessionId),
            'tenders' => $this->tenders($sessionId),
            'drops' => $this->drops($sessionId),
        ];
    }

    public function local(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        return $this->clock->localTime($value);
    }

    /**
     * @return array<string, array{cash: int, card: int, checks: int}>
     */
    private function shiftTenders(string $registerId): array
    {
        $rows = $this->statements->bound(
            'SELECT post.session_id, role.role, COALESCE(sum(line.debit_minor - line.credit_minor), 0) AS net
             FROM register_sessions AS session
             JOIN register_session_posts AS post ON post.session_id = session.id
             JOIN journal_lines AS line ON line.journal_id = post.journal_id
             JOIN journals AS journal ON journal.id = post.journal_id
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE session.register_id = ?::uuid
               AND journal.source_type <> ?
               AND role.role IN (?, ?, ?)
             GROUP BY post.session_id, role.role',
            [$registerId, 'register_variance', 'cash', 'card', 'checks']
        );
        $taken = [];
        foreach ($rows as $row) {
            $sessionId = RowValue::string($row['session_id'] ?? null);
            $role = RowValue::string($row['role'] ?? null);
            if (!isset($taken[$sessionId])) {
                $taken[$sessionId] = ['cash' => 0, 'card' => 0, 'checks' => 0];
            }
            if ($role === 'cash' || $role === 'card' || $role === 'checks') {
                $taken[$sessionId][$role] = RowValue::integer($row['net'] ?? 0);
            }
        }

        return $taken;
    }

    private function expectedCash(string $sessionId): int
    {
        $rows = $this->statements->bound(
            'SELECT stall_register_expected_cash(session.id) AS expected
             FROM register_sessions AS session
             WHERE session.id = ?::uuid',
            [$sessionId]
        );
        if ($rows === []) {
            throw new DatabaseRefusal('that register session is not in this mall');
        }

        return RowValue::integer($rows[0]['expected'] ?? 0);
    }

    /**
     * @return array<string, int>
     */
    private function tenders(string $sessionId): array
    {
        $rows = $this->statements->bound(
            'SELECT role.role, COALESCE(sum(line.debit_minor - line.credit_minor), 0) AS net
             FROM register_session_posts AS post
             JOIN journal_lines AS line ON line.journal_id = post.journal_id
             JOIN journals AS journal ON journal.id = post.journal_id
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE post.session_id = ?::uuid
               AND journal.source_type <> ?
               AND role.role IN (?, ?, ?, ?, ?, ?)
             GROUP BY role.role',
            [$sessionId, 'register_variance', ...self::TENDER_ROLES]
        );
        $tenders = [];
        foreach (self::TENDER_ROLES as $role) {
            $tenders[$role] = 0;
        }
        foreach ($rows as $row) {
            $tenders[RowValue::string($row['role'] ?? null)] = RowValue::integer($row['net'] ?? 0);
        }

        return $tenders;
    }

    /**
     * @return list<array{label: string, amount: string}>
     */
    private function cashLines(string $sessionId, int $opening): array
    {
        $rows = $this->statements->bound(
            'SELECT journal.source_type, COALESCE(sum(line.debit_minor - line.credit_minor), 0) AS net
             FROM register_session_posts AS post
             JOIN journal_lines AS line ON line.journal_id = post.journal_id
             JOIN journals AS journal ON journal.id = post.journal_id
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE post.session_id = ?::uuid
               AND role.role = ?
               AND journal.source_type <> ?
             GROUP BY journal.source_type
             ORDER BY journal.source_type',
            [$sessionId, 'cash', 'register_variance']
        );
        $lines = [[
            'label' => 'Opening count',
            'amount' => DenominationCount::dollars($opening),
        ]];
        foreach ($rows as $row) {
            $source = RowValue::string($row['source_type'] ?? null);
            $net = RowValue::integer($row['net'] ?? 0);
            if ($net === 0) {
                continue;
            }
            $lines[] = [
                'label' => self::CASH_LINES[$source] ?? $source,
                'amount' => DenominationCount::dollars($net),
            ];
        }

        return $lines;
    }

    /**
     * @return list<array{when: string, amount: string}>
     */
    private function drops(string $sessionId): array
    {
        $rows = $this->statements->bound(
            'SELECT journal.occurred_at, line.credit_minor - line.debit_minor AS amount
             FROM register_session_posts AS post
             JOIN journals AS journal ON journal.id = post.journal_id
             JOIN journal_lines AS line ON line.journal_id = journal.id
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE post.session_id = ?::uuid
               AND journal.source_type = ?
               AND role.role = ?
             ORDER BY journal.occurred_at',
            [$sessionId, 'cash_drop', 'cash']
        );
        $drops = [];
        foreach ($rows as $row) {
            $drops[] = [
                'when' => $this->local($row['occurred_at'] ?? null),
                'amount' => DenominationCount::dollars(RowValue::integer($row['amount'] ?? 0)),
            ];
        }

        return $drops;
    }

    /**
     * @return list<array{label: string, count: int}>
     */
    private function grid(mixed $counts): array
    {
        $decoded = is_string($counts) ? json_decode($counts, true) : $counts;
        if (!is_array($decoded)) {
            return [];
        }
        $grid = [];
        foreach (DenominationCount::KEYS as $key) {
            $count = $decoded[(string) $key] ?? $decoded[$key] ?? 0;
            $grid[] = [
                'label' => DenominationCount::LABELS[$key],
                'count' => is_int($count) ? $count : (is_string($count) && ctype_digit($count) ? (int) $count : 0),
            ];
        }

        return $grid;
    }
}
