<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Party;

use PDO;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\RolePaths;
use PerfectConsign\Feature\Dev\DemoSubject;
use PerfectConsign\Feature\Mall\BadDebtRules;
use PerfectConsign\Feature\Party\BadDebts;
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
 * Writing off what a vendor owes, and taking it back, against the real books
 * (D4-D9): the debts and when each can go, the owner's write-off with its
 * reason, the line on the vendor record, the Bad debts report, a recovery, and
 * the store's rules. One transaction, rolled back.
 */
final class BadDebtTest extends TestCase
{
    public function testTheOwnerWritesOffOldRentAndTakesSomeBack(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->connection();
        $schema = $this->schema($pdo);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $session = new Session(null, false);
        DemoSubject::stamp($pdo, $schema, $session);
        $actor = new Actor($session);
        $statements = new Statements($pdo);
        $audit = QuietAudit::journal($statements, $actor, $session);
        $badDebts = new BadDebts($statements, new PostingFunctions($pdo, new LedgerPeriods($pdo), new JournalCash($pdo)), $audit, $actor);
        $moveOut = new VendorMoveOut($statements);
        $money = new VendorMoney($statements);
        $rules = new BadDebtRules($statements, $audit);
        $pdo->beginTransaction();
        try {
            $today = RowValue::string($statements->rows("SELECT to_char((clock_timestamp() AT TIME ZONE time_zone)::date, 'YYYY-MM-DD') AS today FROM mall")[0]['today'] ?? null);
            $tenant = RowValue::string($statements->rows('SELECT id::text AS id FROM platform.tenants WHERE schema_name = current_schema()')[0]['id'] ?? null);
            $rules->save('90', false, $tenant);
            self::assertSame('90', $rules->current()['wait_days']);
            $this->refusedInput('Enter the days as a whole number from 0 to 3650.', fn () => $rules->save('3651', false, $tenant), $pdo);
            $this->refusedInput('Enter the days as a whole number from 0 to 3650.', fn () => $rules->save('ten', false, $tenant), $pdo);

            $tag = bin2hex(random_bytes(3));
            $vendor = RowValue::string($statements->rows('SELECT uuidv7()::text AS id')[0]['id'] ?? null);
            SeedVendor::withId($statements, $vendor, 'w' . $tag, 'Wanda', 'Gone' . $tag);
            $booth = RowValue::string($statements->bound('INSERT INTO booths (code, name) VALUES (?, ?) RETURNING id::text AS id', ['w' . $tag, 'Booth w' . $tag])[0]['id'] ?? null);
            $stay = RowValue::string($statements->bound(
                "INSERT INTO booth_assignments (booth_id, vendor_id, starts_on, ends_on) VALUES (?::uuid, ?::uuid, DATE '2026-01-01', DATE '2026-07-31') RETURNING id::text AS id",
                [$booth, $vendor]
            )[0]['id'] ?? null);
            $statements->change('INSERT INTO assignment_rent (assignment_id, amount_minor) VALUES (?::uuid, 7000)', [$stay]);
            $periods = new LedgerPeriods($pdo);
            $periods->idFor($periods->defaultBookId(), '2026-07-01');
            $periods->idFor($periods->defaultBookId(), $today);
            $statements->bound("SELECT stall_charge_rent(?::uuid, DATE '2026-07-01')", [$stay]);

            $debts = $moveOut->debts($vendor, $today);
            self::assertCount(1, $debts);
            self::assertSame(['kind' => 'rent', 'booth' => 'w' . $tag, 'open' => 7000, 'since' => '2026-07-01', 'eligible_on' => '2026-09-29'], $debts[0]);

            $session->set('actor_role', 'manager');
            $this->refused('Only the owner can do that.', fn () => $badDebts->writeOff($vendor, 'Moved away', $today), $pdo);
            DemoSubject::stamp($pdo, $schema, $session);
            $this->refused('Say why it is written off.', fn () => $badDebts->writeOff($vendor, ' ', $today), $pdo);

            self::assertSame(1, $badDebts->writeOff($vendor, 'Moved out of state', $today));
            self::assertSame([], $moveOut->debts($vendor, $today));
            $written = $moveOut->writeOffs($vendor);
            self::assertCount(1, $written);
            self::assertSame(7000, $written[0]['left']);
            self::assertStringStartsWith('Written off $70.00 on ', $written[0]['said']);
            self::assertStringEndsWith(': Moved out of state.', $written[0]['said']);

            $report = $money->badDebts($today, $today);
            $mine = array_values(array_filter($report, static fn (array $row): bool => $row['vendor_id'] === $vendor));
            self::assertCount(1, $mine);
            self::assertSame(['rent', 7000, 0, 'Moved out of state'], [$mine[0]['kind'], $mine[0]['amount'], $mine[0]['recovered'], $mine[0]['reason']]);

            $this->refused('You can take back up to $70.00 for this write-off.', fn () => $badDebts->recover($written[0]['journal_id'], 7001, $today), $pdo);
            self::assertNotSame('', $badDebts->recover($written[0]['journal_id'], 2500, $today));
            self::assertSame(4500, $moveOut->writeOffs($vendor)[0]['left']);
            self::assertSame(2500, RowValue::integer($statements->bound('SELECT stall_assignment_open_rent(?::uuid) AS open', [$stay])[0]['open'] ?? null));

            self::assertTrue(RolePaths::writeOff('/vendors/' . $vendor . '/write-off'));
            self::assertTrue(RolePaths::writeOff('/consignors/' . $vendor . '/write-offs/' . $stay . '/recover'));
            self::assertFalse(RolePaths::writeOff('/vendors/' . $vendor . '/close-out'));
        } finally {
            $pdo->rollBack();
        }
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

    private function refusedInput(string $message, callable $attempt, PDO $pdo): void
    {
        $pdo->exec('SAVEPOINT refused');
        try {
            $attempt();
            self::fail('accepted: ' . $message);
        } catch (\InvalidArgumentException $refusal) {
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
