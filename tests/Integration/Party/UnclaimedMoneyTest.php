<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Party;

use PDO;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Feature\Dev\DemoSubject;
use PerfectConsign\Feature\Party\UnclaimedMoney;
use PerfectConsign\Feature\Party\VendorMoveOut;
use PerfectConsign\Feature\Report\VendorMoney;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\PostingFunctions;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\QuietAudit;
use PerfectConsign\Tests\Support\SeedVendor;
use PHPUnit\Framework\TestCase;

/**
 * Money the store cannot pay a vendor, against the real books: when it can be
 * moved, the move, paying the vendor in cash on the open register and by check,
 * sending the rest to the state, and the yearly report. Rolled back.
 */
final class UnclaimedMoneyTest extends TestCase
{
    public function testTheOwnerMovesPaysAndSendsUnclaimedMoney(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->connection();
        $schema = $this->schema($pdo);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $session = new Session(null, false);
        DemoSubject::stamp($pdo, $schema, $session);
        $actor = new Actor($session);
        $statements = new Statements($pdo);
        $periods = new LedgerPeriods($pdo);
        $unclaimed = new UnclaimedMoney($statements, new PostingFunctions($pdo, $periods, new JournalCash($pdo)), QuietAudit::journal($statements, $actor, $session), $actor);
        $moveOut = new VendorMoveOut($statements);
        $pdo->beginTransaction();
        try {
            $today = RowValue::string($statements->rows("SELECT to_char((clock_timestamp() AT TIME ZONE time_zone)::date, 'YYYY-MM-DD') AS today FROM mall")[0]['today'] ?? null);
            $tag = bin2hex(random_bytes(3));
            $vendor = RowValue::string($statements->rows('SELECT uuidv7()::text AS id')[0]['id'] ?? null);
            SeedVendor::withId($statements, $vendor, 'u' . $tag, 'Una', 'Gone' . $tag);
            $shift = $this->shift($statements);
            $this->owedFromASale($statements, $periods, $vendor, $shift, 6000, '2026-06-02');

            self::assertSame(['owed' => 6000, 'since' => '2026-06-02', 'movable_on' => '2029-06-01', 'held' => 0], $moveOut->unpaid($vendor));
            $this->refused('What the store owes Una Gone' . $tag . ' can be moved to unclaimed money from 06-01-2029.', fn () => $unclaimed->move($vendor, 'No reply', $today), $pdo);
            $statements->change('UPDATE mall SET unclaimed_dormancy_days = 30', []);
            $session->set('actor_role', 'manager');
            $this->refused('Only the owner can do that.', fn () => $unclaimed->move($vendor, 'No reply', $today), $pdo);
            DemoSubject::stamp($pdo, $schema, $session);

            self::assertNotSame('', $unclaimed->move($vendor, 'Checks never cashed', $today));
            self::assertSame(0, $moveOut->unpaid($vendor)['owed']);
            self::assertSame(6000, $moveOut->unpaid($vendor)['held']);

            $this->refused('Open the register first to take or give back money.', fn () => $unclaimed->pay($vendor, 1000, 'cash', '', '', $today), $pdo);
            $unclaimed->pay($vendor, 1000, 'cash', '', $shift, $today);
            $unclaimed->pay($vendor, 2000, 'check', '1042', '', $today);
            $this->refused('The store holds $30.00 for them.', fn () => $unclaimed->remit($vendor, 3001, 'CA-77', $today), $pdo);
            $unclaimed->remit($vendor, 3000, 'CA-77', $today);
            self::assertSame(0, $moveOut->unpaid($vendor)['held']);

            $rows = array_values(array_filter((new VendorMoney($statements))->unclaimed($today), static fn (array $row): bool => $row['vendor_id'] === $vendor));
            self::assertCount(1, $rows);
            self::assertSame([6000, 3000, 3000, 0, 'CA-77'], [$rows[0]['moved'], $rows[0]['paid'], $rows[0]['remitted'], $rows[0]['held'], $rows[0]['references']]);
        } finally {
            $pdo->rollBack();
        }
    }

    private function owedFromASale(Statements $statements, LedgerPeriods $periods, string $vendor, string $shift, int $amount, string $day): void
    {
        $book = $periods->defaultBookId();
        $period = $periods->idFor($book, $day);
        $periods->idFor($book, RowValue::string($statements->rows("SELECT to_char((clock_timestamp() AT TIME ZONE time_zone)::date, 'YYYY-MM-DD') AS today FROM mall")[0]['today'] ?? null));
        $item = RowValue::string($statements->bound(
            'INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on, commission_bps, quantity)
             VALUES (?, ?::uuid, ?, ?, ?, ?::date, 0, 1) RETURNING id::text AS id',
            ['U' . substr($vendor, -6), $vendor, 'Unclaimed lamp', $amount, 'BC-U' . substr($vendor, -6), $day]
        )[0]['id'] ?? null);
        $journal = RowValue::string($statements->bound(
            "INSERT INTO journals (book_id, posting_key, posting_date, occurred_at, period_id, currency, source_type, source_reference, description, debit_total_minor, credit_total_minor)
             VALUES (?::uuid, ?, ?::date, ?::date, ?::uuid, 'USD', 'sale', ?, 'Lamp sale', ?, ?) RETURNING id::text AS id",
            [$book, 'sale:unclaimed-' . $item, $day, $day, $period, 'unclaimed-' . $item, $amount, $amount]
        )[0]['id'] ?? null);
        $statements->change(
            "INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
             SELECT ?::uuid, ?::uuid, 1, account_id, ?, 0 FROM chart_roles WHERE role = 'cash'",
            [$journal, $book, $amount]
        );
        $statements->change(
            "INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
             SELECT ?::uuid, ?::uuid, 2, account_id, 0, ?, 'vendor', ? FROM chart_roles WHERE role = 'vendor_payable'",
            [$journal, $book, $amount, $vendor]
        );
        $statements->change(
            'INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor) VALUES (?::uuid, 1, ?::uuid, ?, 0, 0)',
            [$journal, $item, $amount]
        );
        $statements->change('INSERT INTO register_session_posts (session_id, journal_id) VALUES (?::uuid, ?::uuid)', [$shift, $journal]);
    }

    private function shift(Statements $statements): string
    {
        $open = $statements->rows(
            "SELECT id::text AS id FROM register_sessions
             WHERE closed_at IS NULL AND register_id = '01900000-0000-7000-8000-000000000d10'::uuid"
        );
        if ($open !== []) {
            return RowValue::string($open[0]['id'] ?? null);
        }

        return RowValue::string($statements->bound(
            "INSERT INTO register_sessions (register_id, cashier, opening_counts, opening_cash_minor)
             VALUES ('01900000-0000-7000-8000-000000000d10'::uuid, 'Ada', ?::jsonb, 0) RETURNING id::text AS id",
            ['{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}']
        )[0]['id'] ?? null);
    }

    private function refused(string $message, callable $attempt, PDO $pdo): void
    {
        $pdo->exec('SAVEPOINT refused');
        try {
            $attempt();
            self::fail('accepted: ' . $message);
        } catch (DatabaseRefusal $refusal) {
            self::assertSame($message, $refusal->getMessage());
        }
        $pdo->exec('ROLLBACK TO SAVEPOINT refused');
    }

    private function schema(PDO $pdo): string
    {
        $listed = $pdo->query("SELECT schema_name FROM platform.tenants WHERE slug = 'webprobe'");
        $schema = $listed === false ? null : $listed->fetchColumn();
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
