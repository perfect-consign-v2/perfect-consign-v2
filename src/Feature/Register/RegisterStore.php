<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Register;

use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class RegisterStore
{
    /** @var list<string> */
    private const DRAWER_SOURCES = [
        'sale',
        'item_return',
        'rent_payment',
        'cash_disbursement',
        'gift_certificate_issue',
        'gift_card_issue',
        'store_credit_issue',
        'cash_drop',
        'register_variance',
    ];

    public function __construct(
        private readonly Statements $statements,
        private readonly MallClock $clock,
        private readonly RegisterActivity $activity,
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
            "SELECT register.id, register.name,
                    (SELECT count(*) FROM register_sessions WHERE register_id = register.id) AS shift_count,
                    session.id AS session_id, session.cashier, session.opened_at, session.opening_cash_minor,
                    CASE WHEN EXISTS (
                        SELECT 1 FROM tickets
                        WHERE session_id = session.id AND status = 'open'
                    ) THEN 1 ELSE 0 END AS open_ticket
             FROM registers AS register
             LEFT JOIN register_sessions AS session
               ON session.register_id = register.id AND session.closed_at IS NULL
             ORDER BY lower(register.name), register.name"
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

    public const GIFT_CERTIFICATE_MISSING = 'that gift certificate number is not on file';

    public const GIFT_CERTIFICATE_BELOW_ZERO = 'a gift certificate cannot go below zero';

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
            $label = str_replace('_', ' ', $kind);
            throw new DatabaseRefusal($kind === 'gift_certificate' ? self::GIFT_CERTIFICATE_MISSING : 'that ' . $label . ' number is not on file');
        }

        return RowValue::string($rows[0]['id'] ?? null);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function card(array $row): array
    {
        $sessionId = RowValue::optionalString($row['session_id'] ?? null);
        $open = $sessionId !== null;
        $drawer = $open ? $this->activity->drawer($sessionId) : ['expected' => 0, 'tenders' => [], 'drops' => []];

        return [
            'id' => RowValue::string($row['id'] ?? null),
            'name' => RowValue::string($row['name'] ?? null),
            'open' => $open,
            'shifts' => RowValue::integer($row['shift_count'] ?? 0),
            'session_id' => $sessionId ?? '',
            'open_ticket' => RowValue::integer($row['open_ticket'] ?? 0) === 1,
            'cashier' => $open ? RowValue::string($row['cashier'] ?? null) : '',
            'opened_at' => $open ? $this->activity->local($row['opened_at'] ?? null) : '',
            'opened_on' => $open ? $this->clock->localDate($row['opened_at'] ?? null) : '',
            'opening_cash' => $open ? DenominationCount::dollars(RowValue::integer($row['opening_cash_minor'] ?? 0)) : '',
            'expected_cash' => $open ? DenominationCount::dollars($drawer['expected']) : '',
            'expected_minor' => $drawer['expected'],
            'tenders' => $drawer['tenders'],
            'drops' => $drawer['drops'],
        ];
    }
    private function name(string $name): string
    {
        if ($name === '' || $name !== trim($name) || strlen($name) > 40) {
            throw new \InvalidArgumentException('a register name is required and at most 40 characters');
        }

        return $name;
    }
}
