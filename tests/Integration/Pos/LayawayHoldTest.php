<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PDO;
use PerfectConsign\Feature\Pos\LayawayBook;
use PerfectConsign\Feature\Pos\TicketPricing;
use PerfectConsign\Feature\Pos\TicketQueue;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;

final class LayawayHoldTest extends TestCase
{
    public function testADepositStaysOnTheLayawayBalance(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $schema = $this->schema($pdo);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $ledger = new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo));
        $book = new LayawayBook($statements, $ledger);
        $registerId = $ledger->newId();
        $sessionId = $ledger->newId();
        $vendorId = $ledger->newId();
        $itemId = $ledger->newId();
        $ticketId = $ledger->newId();
        $code = 'bal' . substr(str_replace('-', '', $ticketId), -12);
        $statements->change(
            'INSERT INTO registers (id, name) VALUES (?::uuid, ?)',
            [$registerId, 'Balance proof ' . $code]
        );
        $statements->change(
            'INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
             VALUES (?::uuid, ?::uuid, ?, ?::jsonb, 0)',
            [$sessionId, $registerId, 'Ada', '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}']
        );
        $statements->change(
            'INSERT INTO vendors (id, code, first_name, last_name) VALUES (?::uuid, ?, ?, ?)',
            [$vendorId, $code, 'Balance', 'Proof']
        );
        $statements->change(
            'INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity)
             VALUES (?::uuid, ?, ?::uuid, ?, 1000, ?, ?::date, 1)',
            [$itemId, $code, $vendorId, 'Held coat', 'BC-' . $code, '2026-06-15']
        );
        $statements->change(
            'INSERT INTO tickets (id, session_id) VALUES (?::uuid, ?::uuid)',
            [$ticketId, $sessionId]
        );
        $statements->change(
            'INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor) VALUES (?::uuid, 1, ?::uuid, 1000)',
            [$ticketId, $itemId]
        );
        $cash = $ledger->roleAccount('cash');
        try {
            $book->hold($sessionId, '2026-06-15', $ticketId, 1000, [
                'lines' => [PostedLines::entry($cash, 400, 0, null, null)],
                'sum' => 400,
                'check' => null,
                'cash' => 400,
            ]);
            self::assertSame(400, $book->balance($ticketId));
            $book->cancel($sessionId, '2026-06-15', $ticketId);
            self::assertSame(0, $book->balance($ticketId));
        } finally {
            $statements->change(
                'UPDATE register_sessions
                 SET closing_counts = opening_counts,
                     counted_cash_minor = 0,
                     counted_card_minor = 0,
                     counted_checks_minor = 0,
                     closed_at = clock_timestamp()
                 WHERE id = ?::uuid AND closed_at IS NULL',
                [$sessionId]
            );
        }
    }

    public function testTheLayawayListNamesTheShopperNotTheBuyer(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $schema = $this->schema($pdo);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $ledger = new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo));
        $book = new LayawayBook($statements, $ledger);
        $queue = new TicketQueue($statements, new TicketPricing($statements));
        $registerId = $ledger->newId();
        $sessionId = $ledger->newId();
        $vendorId = $ledger->newId();
        $customerId = $ledger->newId();
        $itemId = $ledger->newId();
        $ticketId = $ledger->newId();
        $code = 'shop' . substr(str_replace('-', '', $ticketId), -12);
        $shopper = 'Shopper ' . $code;
        $statements->change(
            'INSERT INTO registers (id, name) VALUES (?::uuid, ?)',
            [$registerId, 'Shopper list ' . $code]
        );
        $statements->change(
            'INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
             VALUES (?::uuid, ?::uuid, ?, ?::jsonb, 0)',
            [$sessionId, $registerId, 'Ada', '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}']
        );
        $statements->change(
            'INSERT INTO vendors (id, code, first_name, last_name) VALUES (?::uuid, ?, ?, ?)',
            [$vendorId, $code, 'Stock', 'Owner']
        );
        $statements->change(
            'INSERT INTO customers (id, name, phone, email) VALUES (?::uuid, ?, ?, ?)',
            [$customerId, $shopper, '555-0100', 'shopper@example.com']
        );
        $statements->change(
            'INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity)
             VALUES (?::uuid, ?, ?::uuid, ?, 1000, ?, ?::date, 1)',
            [$itemId, $code, $vendorId, 'Held coat', 'BC-' . $code, '2026-06-15']
        );
        $statements->change(
            'INSERT INTO tickets (id, session_id, customer_id) VALUES (?::uuid, ?::uuid, ?::uuid)',
            [$ticketId, $sessionId, $customerId]
        );
        $statements->change(
            'INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor) VALUES (?::uuid, 1, ?::uuid, 1000)',
            [$ticketId, $itemId]
        );
        $cash = $ledger->roleAccount('cash');
        try {
            $book->hold($sessionId, '2026-06-15', $ticketId, 1000, [
                'lines' => [PostedLines::entry($cash, 400, 0, null, null)],
                'sum' => 400,
                'check' => null,
                'cash' => 400,
            ]);
            $named = array_values(array_filter(
                $queue->layaways(),
                static fn (array $row): bool => $row['id'] === $ticketId
            ));
            self::assertCount(1, $named);
            self::assertSame($shopper, $named[0]['customer']);
            self::assertSame('555-0100', $named[0]['phone']);
            self::assertSame('shopper@example.com', $named[0]['email']);
            $book->cancel($sessionId, '2026-06-15', $ticketId);
        } finally {
            $statements->change(
                'UPDATE register_sessions
                 SET closing_counts = opening_counts,
                     counted_cash_minor = 0,
                     counted_card_minor = 0,
                     counted_checks_minor = 0,
                     closed_at = clock_timestamp()
                 WHERE id = ?::uuid AND closed_at IS NULL',
                [$sessionId]
            );
        }
    }

    public function testAFailedHoldRollsTheDepositBack(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $schema = $this->schema($pdo);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $ledger = new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo));
        $book = new LayawayBook($statements, $ledger);
        $registerId = $ledger->newId();
        $sessionId = $ledger->newId();
        $vendorId = $ledger->newId();
        $itemId = $ledger->newId();
        $ticketId = $ledger->newId();
        $code = 'hold' . substr(str_replace('-', '', $ticketId), -12);
        $statements->change(
            'INSERT INTO registers (id, name) VALUES (?::uuid, ?)',
            [$registerId, 'Hold proof ' . $code]
        );
        $statements->change(
            'INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
             VALUES (?::uuid, ?::uuid, ?, ?::jsonb, 0)',
            [$sessionId, $registerId, 'Ada', '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}']
        );
        $statements->change(
            'INSERT INTO vendors (id, code, first_name, last_name) VALUES (?::uuid, ?, ?, ?)',
            [$vendorId, $code, 'Hold', 'Proof']
        );
        $statements->change(
            'INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity)
             VALUES (?::uuid, ?, ?::uuid, ?, 1000, ?, ?::date, 1)',
            [$itemId, $code, $vendorId, 'Held coat', 'BC-' . $code, '2026-06-15']
        );
        $statements->change(
            'INSERT INTO tickets (id, session_id) VALUES (?::uuid, ?::uuid)',
            [$ticketId, $sessionId]
        );
        $statements->change(
            'INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor) VALUES (?::uuid, 1, ?::uuid, 1000)',
            [$ticketId, $itemId]
        );
        $statements->change(
            "UPDATE tickets SET status = 'layaway' WHERE id = ?::uuid AND status = 'open'",
            [$ticketId]
        );
        $cash = $ledger->roleAccount('cash');
        try {
            $book->hold($sessionId, '2026-06-15', $ticketId, 1000, [
                'lines' => [PostedLines::entry($cash, 400, 0, null, null)],
                'sum' => 400,
                'check' => null,
                'cash' => 400,
            ]);
            self::fail('a layaway hold posted on a ticket that was already held');
        } catch (DatabaseRefusal $error) {
            self::assertSame('there is no open ticket', $error->getMessage());
        }
        $left = $statements->bound(
            "SELECT id FROM journals WHERE source_type = 'layaway_deposit' AND source_reference = ?",
            [$ticketId]
        );
        self::assertSame([], $left);
        $statements->change('DELETE FROM tickets WHERE id = ?::uuid', [$ticketId]);
        $statements->change(
            'UPDATE register_sessions
             SET closing_counts = opening_counts,
                 counted_cash_minor = 0,
                 counted_card_minor = 0,
                 counted_checks_minor = 0,
                 closed_at = clock_timestamp()
             WHERE id = ?::uuid AND closed_at IS NULL',
            [$sessionId]
        );
    }

    private function schema(PDO $pdo): string
    {
        $listed = $pdo->query("SELECT schema_name FROM platform.tenants WHERE slug = 'webprobe'");
        if ($listed === false) {
            self::fail('webprobe schema is missing');
        }
        $schema = $listed->fetchColumn();
        if (!is_string($schema) || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            self::fail('webprobe schema is missing');
        }

        return $schema;
    }

    private function connection(): PDO
    {
        return new PDO(
            'pgsql:host=' . $this->env('STALL_DB_HOST') . ';port=' . $this->env('STALL_DB_PORT') . ';dbname=' . $this->env('STALL_DB_NAME'),
            $this->env('STALL_DB_USER'),
            $this->env('STALL_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            self::fail($name . ' is required');
        }

        return $value;
    }
}
