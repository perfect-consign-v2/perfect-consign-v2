<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Register;

use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class RegisterStore
{
    /** @var list<string> */
    private const DRAWER_SOURCES = [
        'sale',
        'rent_payment',
        'payout',
        'gift_certificate_issue',
        'gift_card_issue',
        'store_credit_issue',
        'cash_drop',
        'register_variance',
    ];

    /** @var array<string, string> */
    private const TENDER_ROLES = [
        'cash' => 'Cash',
        'card' => 'Card',
        'checks' => 'Checks',
        'gift_certificate' => 'Gift certificates',
        'gift_card' => 'Gift cards',
        'store_credit' => 'Store credit',
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
        private readonly LedgerStore $ledger,
        private readonly MallSettings $mall,
    ) {
    }

    public function drawerSource(string $source): bool
    {
        return in_array($source, self::DRAWER_SOURCES, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cards(): array
    {
        $rows = $this->statements->rows(
            'SELECT register.id, register.name,
                    (SELECT count(*) FROM register_sessions WHERE register_id = register.id) AS shift_count,
                    session.id AS session_id, session.cashier, session.opened_at, session.opening_cash_minor
             FROM registers AS register
             LEFT JOIN register_sessions AS session
               ON session.register_id = register.id AND session.closed_at IS NULL
             ORDER BY lower(register.name), register.name'
        );
        $cards = [];
        foreach ($rows as $row) {
            $cards[] = $this->card($row);
        }

        return $cards;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $rows = $this->statements->bound('SELECT id, name FROM registers WHERE id = ?::uuid', [$id]);
        if ($rows === []) {
            return null;
        }

        return $rows[0];
    }

    public function add(string $name): void
    {
        $this->statements->change('INSERT INTO registers (name) VALUES (?)', [$this->name($name)]);
    }

    public function remove(string $id): void
    {
        $rows = $this->statements->bound(
            'SELECT (SELECT count(*) FROM registers) AS registers,
                    (SELECT count(*) FROM register_sessions WHERE register_id = registers.id) AS shifts
             FROM registers
             WHERE id = ?::uuid',
            [$id]
        );
        if ($rows === []) {
            throw new DatabaseRefusal('that register is not in this mall');
        }
        if (RowValue::integer($rows[0]['registers'] ?? 0) <= 1) {
            throw new DatabaseRefusal('The last register cannot be deleted.');
        }
        if (RowValue::integer($rows[0]['shifts'] ?? 0) > 0) {
            throw new DatabaseRefusal('A register that has been opened cannot be deleted.');
        }
        $deleted = $this->statements->affected('DELETE FROM registers WHERE id = ?::uuid', [$id]);
        if ($deleted !== 1) {
            throw new DatabaseRefusal('that register is not in this mall');
        }
    }

    public function rename(string $id, string $name): void
    {
        $updated = $this->statements->affected(
            'UPDATE registers SET name = ? WHERE id = ?::uuid',
            [$this->name($name), $id]
        );
        if ($updated !== 1) {
            throw new DatabaseRefusal('that register is not in this mall');
        }
    }

    /**
     * @param array<string, int> $counts
     */
    public function open(string $registerId, string $cashier, array $counts, int $typedFloat): string
    {
        $total = DenominationCount::total($counts);
        if ($total === 0) {
            $counts = DenominationCount::forAmount($typedFloat);
            $total = $typedFloat;
        }
        $rows = $this->statements->bound(
            'INSERT INTO register_sessions (register_id, cashier, opening_counts, opening_cash_minor)
             VALUES (?::uuid, ?, ?::jsonb, ?)
             RETURNING id',
            [$registerId, $this->cashier($cashier), DenominationCount::json($counts), $total]
        );
        if ($rows === []) {
            throw new DatabaseRefusal('the register did not open');
        }

        return RowValue::string($rows[0]['id'] ?? null);
    }

    /**
     * @param array<string, int> $counts
     */
    public function close(string $registerId, array $counts, int $typedCash, int $card, int $checks, bool $confirmed, string $postingDate): string
    {
        $sessionId = $this->openSessionId($registerId);
        $fromBills = DenominationCount::total($counts);
        if ($fromBills !== $typedCash) {
            $counts = DenominationCount::forAmount($typedCash);
        }
        $counted = $typedCash;
        $expected = $this->expectedCash($sessionId);
        $difference = $counted - $expected;
        if ($difference !== 0 && !$confirmed) {
            throw new DatabaseRefusal('counted cash does not match the expected drawer');
        }
        $json = DenominationCount::json($counts);
        if ($difference === 0) {
            $this->writeClose($sessionId, $json, $counted, $card, $checks);

            return $sessionId;
        }
        $amount = abs($difference);
        $cash = $this->ledger->roleAccount('cash');
        $short = $this->ledger->roleAccount('cash_over_short');
        $lines = $difference > 0
            ? [
                PostedLines::entry($cash, $amount, 0, null, null),
                PostedLines::entry($short, 0, $amount, null, null),
            ]
            : [
                PostedLines::entry($short, $amount, 0, null, null),
                PostedLines::entry($cash, 0, $amount, null, null),
            ];
        $key = $this->ledger->newId();
        $this->ledger->post(
            'register-variance:' . $key,
            $postingDate,
            'register_variance',
            $key,
            'Register over/short',
            $lines,
            null,
            $sessionId,
            null,
            function () use ($sessionId, $json, $counted, $card, $checks): void {
                $this->writeClose($sessionId, $json, $counted, $card, $checks);
            }
        );

        return $sessionId;
    }

    public function drop(string $registerId, int $amount, string $postingDate): void
    {
        $sessionId = $this->openSessionId($registerId);
        $openedOn = $this->mall->localDate($this->openedAt($registerId));
        if ($openedOn !== '' && $postingDate < $openedOn) {
            throw new DatabaseRefusal('A cash drop cannot be dated before the register was opened.');
        }
        $key = $this->ledger->newId();
        $this->ledger->post(
            'cash-drop:' . $key,
            $postingDate,
            'cash_drop',
            $key,
            'Cash drop',
            [
                PostedLines::entry($this->ledger->roleAccount('cash_in_safe'), $amount, 0, null, null),
                PostedLines::entry($this->ledger->roleAccount('cash'), 0, $amount, null, null),
            ],
            null,
            $sessionId
        );
    }

    public function openSession(string $requested): string
    {
        $open = $this->openSessions();
        if ($requested !== '') {
            foreach ($open as $session) {
                if ($session['id'] === $requested) {
                    return $requested;
                }
            }
            throw new DatabaseRefusal('a drawer journal needs an open register');
        }
        if (count($open) === 1) {
            return $open[0]['id'];
        }
        if ($open === []) {
            throw new DatabaseRefusal('a drawer journal needs an open register');
        }

        throw new DatabaseRefusal('Choose the active register.');
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function openSessions(): array
    {
        $rows = $this->statements->rows(
            'SELECT session.id, register.name
             FROM register_sessions AS session
             JOIN registers AS register ON register.id = session.register_id
             WHERE session.closed_at IS NULL
             ORDER BY lower(register.name), register.name'
        );
        $open = [];
        foreach ($rows as $row) {
            $open[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'name' => RowValue::string($row['name'] ?? null),
            ];
        }

        return $open;
    }

    public function storedValueId(string $kind, string $number): string
    {
        $number = trim($number);
        if ($number === '' || strlen($number) > 40 || $number !== trim($number)) {
            throw new DatabaseRefusal('a stored value number is required');
        }
        $rows = $this->statements->bound(
            'SELECT id FROM stored_values WHERE kind = ? AND lower(number) = lower(?)',
            [$kind, $number]
        );
        if ($rows === []) {
            throw new DatabaseRefusal('that ' . str_replace('_', ' ', $kind) . ' number is not on file');
        }

        return RowValue::string($rows[0]['id'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function shifts(string $registerId): array
    {
        $rows = $this->statements->bound(
            'SELECT id, cashier, opened_at, closed_at, opening_cash_minor, counted_cash_minor
             FROM register_sessions
             WHERE register_id = ?::uuid
             ORDER BY opened_at DESC',
            [$registerId]
        );
        $shifts = [];
        foreach ($rows as $row) {
            $row['opened_at'] = $this->when($row['opened_at'] ?? null);
            $row['closed_at'] = $this->when($row['closed_at'] ?? null);
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
        $tenders = $this->tenders($sessionId);
        $expected = $this->expectedCash($sessionId);

        return [
            'register_id' => RowValue::string($row['register_id'] ?? null),
            'name' => RowValue::string($row['name'] ?? null),
            'cashier' => RowValue::string($row['cashier'] ?? null),
            'opened_at' => $this->when($row['opened_at'] ?? null),
            'closed_at' => $closed ? $this->when($row['closed_at'] ?? null) : '',
            'closed' => $closed,
            'opening' => $this->grid($row['opening_counts'] ?? null),
            'closing' => $closed ? $this->grid($row['closing_counts'] ?? null) : [],
            'opening_cash' => DenominationCount::dollars(RowValue::integer($row['opening_cash_minor'] ?? 0)),
            'counted_cash' => $closed ? DenominationCount::dollars(RowValue::integer($row['counted_cash_minor'] ?? 0)) : '',
            'expected' => DenominationCount::dollars($expected),
            'expected_minor' => $expected,
            'counted_minor' => $closed ? RowValue::integer($row['counted_cash_minor'] ?? 0) : 0,
            'lines' => $this->cashLines($sessionId, RowValue::integer($row['opening_cash_minor'] ?? 0)),
            'drops' => $this->drops($sessionId),
            'tenders' => $tenders,
            'counted_card' => $closed ? DenominationCount::dollars(RowValue::integer($row['counted_card_minor'] ?? 0)) : '',
            'counted_checks' => $closed ? DenominationCount::dollars(RowValue::integer($row['counted_checks_minor'] ?? 0)) : '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function card(array $row): array
    {
        $sessionId = RowValue::optionalString($row['session_id'] ?? null);
        $open = $sessionId !== null;
        $expected = $open ? $this->expectedCash($sessionId) : 0;
        $tenders = $open ? $this->tenders($sessionId) : [];

        return [
            'id' => RowValue::string($row['id'] ?? null),
            'name' => RowValue::string($row['name'] ?? null),
            'open' => $open,
            'shifts' => RowValue::integer($row['shift_count'] ?? 0),
            'session_id' => $sessionId ?? '',
            'cashier' => $open ? RowValue::string($row['cashier'] ?? null) : '',
            'opened_at' => $open ? $this->when($row['opened_at'] ?? null) : '',
            'opened_on' => $open ? $this->mall->localDate($row['opened_at'] ?? null) : '',
            'opening_cash' => $open ? DenominationCount::dollars(RowValue::integer($row['opening_cash_minor'] ?? 0)) : '',
            'expected_cash' => $open ? DenominationCount::dollars($expected) : '',
            'expected_minor' => $expected,
            'tenders' => $tenders,
            'drops' => $open ? $this->drops($sessionId) : [],
        ];
    }

    private function expectedCash(string $sessionId): int
    {
        $rows = $this->statements->bound(
            'SELECT session.opening_cash_minor + COALESCE((
                SELECT sum(line.debit_minor - line.credit_minor)
                FROM journal_lines AS line
                JOIN chart_roles AS role ON role.account_id = line.account_id
                JOIN register_session_posts AS post ON post.journal_id = line.journal_id
                JOIN journals AS journal ON journal.id = line.journal_id
                WHERE post.session_id = session.id
                  AND role.role = ?
                  AND journal.source_type <> ?
             ), 0) AS expected
             FROM register_sessions AS session
             WHERE session.id = ?::uuid',
            ['cash', 'register_variance', $sessionId]
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
            [
                $sessionId,
                'register_variance',
                'cash',
                'card',
                'checks',
                'gift_certificate',
                'gift_card',
                'store_credit',
            ]
        );
        $tenders = [];
        foreach (array_keys(self::TENDER_ROLES) as $role) {
            $tenders[$role] = 0;
        }
        foreach ($rows as $row) {
            $role = RowValue::string($row['role'] ?? null);
            $tenders[$role] = RowValue::integer($row['net'] ?? 0);
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
                'when' => $this->when($row['occurred_at'] ?? null),
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
            $count = $decoded[(string) $key] ?? 0;
            $grid[] = [
                'label' => DenominationCount::LABELS[$key],
                'count' => is_int($count) ? $count : (is_string($count) && ctype_digit($count) ? (int) $count : 0),
            ];
        }

        return $grid;
    }

    private function writeClose(string $sessionId, string $json, int $cash, int $card, int $checks): void
    {
        $updated = $this->statements->affected(
            'UPDATE register_sessions
             SET closing_counts = ?::jsonb,
                 counted_cash_minor = ?,
                 counted_card_minor = ?,
                 counted_checks_minor = ?,
                 closed_at = clock_timestamp()
             WHERE id = ?::uuid AND closed_at IS NULL',
            [$json, $cash, $card, $checks, $sessionId]
        );
        if ($updated !== 1) {
            throw new DatabaseRefusal('the register is not open');
        }
    }

    private function openedAt(string $registerId): string
    {
        $rows = $this->statements->bound(
            'SELECT opened_at FROM register_sessions WHERE register_id = ?::uuid AND closed_at IS NULL',
            [$registerId]
        );
        if ($rows === []) {
            return '';
        }

        return is_string($rows[0]['opened_at'] ?? null) ? $rows[0]['opened_at'] : '';
    }

    private function openSessionId(string $registerId): string
    {
        $rows = $this->statements->bound(
            'SELECT id FROM register_sessions WHERE register_id = ?::uuid AND closed_at IS NULL',
            [$registerId]
        );
        if ($rows === []) {
            throw new DatabaseRefusal('a drawer journal needs an open register');
        }

        return RowValue::string($rows[0]['id'] ?? null);
    }

    private function name(string $name): string
    {
        if ($name === '' || $name !== trim($name) || strlen($name) > 40) {
            throw new \InvalidArgumentException('a register name is required and at most 40 characters');
        }

        return $name;
    }

    private function cashier(string $cashier): string
    {
        if ($cashier === '' || $cashier !== trim($cashier) || strlen($cashier) > 80) {
            throw new \InvalidArgumentException('a cashier name is required');
        }

        return $cashier;
    }

    private function when(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        return $this->mall->localTime($value);
    }
}
