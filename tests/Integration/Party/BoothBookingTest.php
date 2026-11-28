<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Party;

use PDO;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Feature\Dev\DemoSubject;
use PerfectConsign\Feature\Party\AssignmentBook;
use PerfectConsign\Feature\Party\BoothBooking;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostingFunctions;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\QuietAudit;
use PerfectConsign\Tests\Support\SeedVendor;
use PHPUnit\Framework\TestCase;

/**
 * The desk's booth booking against the real books: what is due, assigning
 * with the first rent, a reservation held and then cancelled, and ending a
 * booth. One transaction, rolled back.
 */
final class BoothBookingTest extends TestCase
{
    private const TODAY = '2026-11-15';

    public function testTheDeskTakesTheDatabasesAmountsAndRefusesInPlainWords(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->connection();
        $schema = $this->schema($pdo);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $session = new Session(null, false);
        DemoSubject::stamp($pdo, $schema, $session);
        $statements = new Statements($pdo);
        $ledger = new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo));
        $booking = new BoothBooking($statements, new PostingFunctions($pdo, new LedgerPeriods($pdo), new JournalCash($pdo)), QuietAudit::journal($statements, new Actor($session), $session));
        $book = new AssignmentBook($statements);
        $pdo->beginTransaction();
        try {
            $vendorId = $ledger->newId();
            $tag = substr(str_replace('-', '', $vendorId), 0, 6);
            SeedVendor::withId($statements, $vendorId, 'k' . $tag, 'Kim', $tag);
            $now = $this->booth($statements, $ledger, 'n' . $tag, 3000);
            $later = $this->booth($statements, $ledger, 'l' . $tag, 6000);
            $session = $this->session($statements, $ledger);
            $card = ['way' => 'card', 'check' => '', 'session' => $session];

            self::assertSame('2026-11-01', $book->earliestStart(self::TODAY));
            self::assertSame(
                ['due' => 1400, 'first_period' => '2026-11-01', 'period_end' => '2026-11-30', 'paid_ahead' => false],
                $booking->due($now, '2026-11-17', '', null, self::TODAY),
            );
            $this->refused('The start date can\'t be before 11-01-2026.', fn () => $booking->due($now, '2026-10-01', '', null, self::TODAY), $pdo);
            $this->refused('Choose how they pay.', fn () => $booking->assign($this->stay($now, $vendorId, '2026-11-17'), ['way' => 'gold', 'check' => '', 'session' => $session], self::TODAY), $pdo);
            $this->refused('Enter the check number.', fn () => $booking->assign($this->stay($now, $vendorId, '2026-11-17'), ['way' => 'check', 'check' => '', 'session' => $session], self::TODAY), $pdo);
            $this->refused('Open the register first to take or give back money.', fn () => $booking->assign($this->stay($now, $vendorId, '2026-11-17'), ['way' => 'cash', 'check' => '', 'session' => ''], self::TODAY), $pdo);

            $receipt = $booking->assign($this->stay($now, $vendorId, '2026-11-17'), $card, self::TODAY);
            $stayNow = $this->stayOn($statements, $now);
            self::assertNotSame('', $receipt);
            self::assertSame(0, $this->open($statements, $stayNow));
            self::assertSame([], $book->paidAhead($vendorId));

            $booking->assign($this->stay($later, $vendorId, '2026-12-01'), $card, self::TODAY);
            $reserved = $this->stayOn($statements, $later);
            self::assertSame(6000, $booking->paidAhead($reserved));
            self::assertSame([$reserved => 6000], $book->paidAhead($vendorId));
            $booking->cancel($reserved, 'refund', $card, 'Changed plans', '2026-11-16');
            self::assertSame(0, $booking->paidAhead($reserved));
            self::assertSame([], $book->paidAhead($vendorId));

            $this->refused('The end date can\'t be before the start date.', fn () => $booking->end($stayNow, '2026-11-16'), $pdo);
            $booking->end($stayNow, '2026-11-30');
            $ended = $statements->bound("SELECT to_char(ends_on, 'YYYY-MM-DD') AS ends_on FROM booth_assignments WHERE id = ?::uuid", [$stayNow]);
            self::assertSame('2026-11-30', RowValue::string($ended[0]['ends_on'] ?? null));
        } finally {
            $pdo->rollBack();
        }
    }

    /**
     * @return array{booth: string, vendor: string, starts_on: string, ends_on: string, rent: ?int}
     */
    private function stay(string $boothId, string $vendorId, string $startsOn): array
    {
        return ['booth' => $boothId, 'vendor' => $vendorId, 'starts_on' => $startsOn, 'ends_on' => '', 'rent' => null];
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

    private function booth(Statements $statements, LedgerStore $ledger, string $code, int $rate): string
    {
        $id = $ledger->newId();
        $statements->change('INSERT INTO booths (id, code, name) VALUES (?::uuid, ?, ?)', [$id, $code, 'Booth ' . $code]);
        $statements->change('INSERT INTO booth_rent_rates (booth_id, amount_minor) VALUES (?::uuid, ?)', [$id, $rate]);

        return $id;
    }

    private function stayOn(Statements $statements, string $boothId): string
    {
        $rows = $statements->bound('SELECT id::text AS id FROM booth_assignments WHERE booth_id = ?::uuid ORDER BY starts_on DESC LIMIT 1', [$boothId]);

        return RowValue::string($rows[0]['id'] ?? null);
    }

    private function open(Statements $statements, string $stayId): int
    {
        $rows = $statements->bound('SELECT stall_assignment_open_rent(?::uuid) AS open_minor', [$stayId]);

        return RowValue::integer($rows[0]['open_minor'] ?? null);
    }

    private function session(Statements $statements, LedgerStore $ledger): string
    {
        $open = $statements->rows(
            "SELECT id::text AS id FROM register_sessions
             WHERE closed_at IS NULL AND register_id = '01900000-0000-7000-8000-000000000d10'::uuid"
        );
        if ($open !== []) {
            return RowValue::string($open[0]['id'] ?? null);
        }
        $id = $ledger->newId();
        $statements->change(
            "INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
             VALUES (?::uuid, '01900000-0000-7000-8000-000000000d10'::uuid, ?, ?::jsonb, 0)",
            [$id, 'Ada', '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}']
        );

        return $id;
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
