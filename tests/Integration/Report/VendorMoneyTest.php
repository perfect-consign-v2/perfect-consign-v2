<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Report;

use PDO;
use PerfectConsign\Feature\Booth\BoothStayChoices;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Party\RentHistory;
use PerfectConsign\Feature\Party\VendorBalances;
use PerfectConsign\Feature\Party\VendorYearPaid;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Feature\Report\VendorMoney;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostingFunctions;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\SeedVendor;
use PHPUnit\Framework\TestCase;

/**
 * The PHP readers for the vendor money reports, the rent history card, and
 * booth extras, against the real books: each shows the database's answer.
 * One transaction, rolled back.
 */
final class VendorMoneyTest extends TestCase
{
    private const MONTH = '2026-04-01';

    public function testTheReadersShowWhatTheDatabaseDecided(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->connection();
        $pdo->exec('SET search_path TO ' . $this->schema($pdo) . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $periods = new LedgerPeriods($pdo);
        $cash = new JournalCash($pdo);
        $ledger = new LedgerStore($pdo, $statements, $periods, $cash);
        $postings = new PostingFunctions($pdo, $periods, $cash);
        $money = new VendorMoney($statements);
        $pdo->beginTransaction();
        try {
            $book = $statements->rows('SELECT id::text AS id FROM ledger_books WHERE is_default');
            self::assertSame(RowValue::string($book[0]['id'] ?? null), $periods->defaultBookId());

            $vendorId = $ledger->newId();
            $tag = substr(str_replace('-', '', $vendorId), 0, 6);
            SeedVendor::withId($statements, $vendorId, 'm' . $tag, 'Mona', $tag);
            $boothId = $ledger->newId();
            $statements->change('INSERT INTO booths (id, code, name) VALUES (?::uuid, ?, ?)', [$boothId, 'q' . $tag, 'Booth q' . $tag]);
            $stay = $statements->bound(
                'INSERT INTO booth_assignments (booth_id, vendor_id, starts_on, ends_on) VALUES (?::uuid, ?::uuid, ?::date, ?::date) RETURNING id::text AS id',
                [$boothId, $vendorId, self::MONTH, '2026-04-30']
            );
            $stayId = RowValue::string($stay[0]['id'] ?? null);
            $statements->change('INSERT INTO assignment_rent (assignment_id, amount_minor) VALUES (?::uuid, ?)', [$stayId, 5000]);

            $charge = $postings->post(self::MONTH, 'stall_post_rent_charge(?::uuid, ?::date, ?::date)', [$stayId, self::MONTH, self::MONTH]);
            self::assertNotSame('', $charge);
            $statements->change(
                'UPDATE mall SET late_fees_enabled = true, late_fee_grace_days = 28, late_fee_first_minor = 700,
                        late_fee_next_minor = 700, late_fee_effective_on = ?::date, booth_deposits_enabled = false',
                [self::MONTH]
            );
            $before = $this->fees($statements, '');
            $posted = $postings->postBatch(['2026-04-29'], 'stall_post_late_fees(?::date)', ['2026-04-30']);
            self::assertSame($posted, $this->fees($statements, '') - $before);
            self::assertSame(1, $this->fees($statements, $stayId));

            $session = $this->session($statements, $ledger);
            $receipt = $postings->post(
                '2026-04-30',
                'stall_post_rent_payment(?::uuid, ?, ?, ?, ?::date)',
                [$vendorId, 0, 3000, 0, '2026-04-30'],
                $session,
            );
            // Posting with a shift links the receipt to it once; linking nothing more adds nothing.
            $cash->linkDrawer($receipt, null, null);
            $linked = $statements->bound(
                'SELECT (SELECT count(*) FROM register_session_posts WHERE journal_id = ?::uuid) AS links,
                        (SELECT count(*) FROM register_checks WHERE journal_id = ?::uuid) AS checks',
                [$receipt, $receipt]
            );
            self::assertSame([1, 0], [RowValue::integer($linked[0]['links'] ?? null), RowValue::integer($linked[0]['checks'] ?? null)]);

            $history = (new RentHistory($statements))->forVendor($vendorId, false);
            self::assertFalse($history['more']);
            self::assertSame(self::MONTH, $history['months'][0]['month'] ?? null);
            $rent = $history['months'][0]['charges'][0] ?? null;
            self::assertIsArray($rent);
            self::assertSame(['rent', 5000, 3000, 2000], [$rent['kind'], $rent['charged'], $rent['paid'], $rent['owed']]);
            self::assertSame('Card', $rent['payments'][0]['how'] ?? null);
            self::assertNotSame('', $rent['payments'][0]['cashier'] ?? '');
            $runs = new PayoutRunStore($statements, new OccupiedPack($statements));
            $balances = new VendorBalances(
                new PayoutStore($pdo, $statements, $runs, new PayoutWindow()),
                new VendorClawback($ledger, $statements),
                new VendorYearPaid($statements),
                new RentHistory($statements),
            );
            self::assertSame($history, $balances->rentHistory($vendorId, false));
            self::assertSame($history['months'], $balances->rentHistory($vendorId, true)['months']);

            $filters = ['vendor' => $vendorId, 'booth' => '', 'paid_by' => '', 'q' => ''];
            $payments = $money->payments('2026-04-01', '2026-04-30', $filters);
            self::assertCount(1, $payments);
            self::assertSame(['card', 'Apr 2026', 3000, false], [
                $payments[0]['paid_by'], $payments[0]['months'], $payments[0]['amount'], $payments[0]['voided'],
            ]);
            self::assertNotSame('', $payments[0]['register']);
            $totals = $money->paymentTotals('2026-04-01', '2026-04-30', $filters);
            self::assertSame(['all', 1, 3000], [$totals[0]['kind'], $totals[0]['count'], $totals[0]['amount']]);

            $register = $money->register($vendorId, '2026-04-01', '2026-04-30', '');
            $closing = array_values(array_filter($register, static fn (array $row): bool => $row['kind'] === 'closing'));
            self::assertSame('opening', $register[0]['kind']);
            self::assertSame(0, $register[0]['balance']);
            self::assertSame(-(5000 + 700 - 3000), $closing[0]['balance'] ?? null);
            $pdo->exec('SAVEPOINT unknown_vendor');
            try {
                $money->register($ledger->newId(), '2026-04-01', '2026-04-30', '');
                self::fail('a register opened for a vendor who does not exist');
            } catch (DatabaseRefusal $refusal) {
                self::assertSame('Choose a vendor.', $refusal->getMessage());
            }
            $pdo->exec('ROLLBACK TO SAVEPOINT unknown_vendor');
            self::assertTrue(VendorMoney::isId($vendorId));
            self::assertFalse(VendorMoney::isId('Booth 12'));
            self::assertContains(['id' => $boothId, 'label' => 'Booth q' . $tag], $money->booths());
            self::assertContains(['id' => $vendorId, 'label' => 'Mona ' . $tag . ' (m' . $tag . ')'], $money->vendors());

            $settings = new MallPayoutSettings($statements);
            $choices = new BoothStayChoices($statements);
            self::assertFalse($settings->isDepositsEnabled());
            self::assertFalse($choices->depositsEnabled());
            self::assertContains(['id' => $stayId, 'label' => 'Booth q' . $tag . ', Mona ' . $tag], $choices->current('2026-11-27'));
            $settings->saveDeposits(true);
            self::assertTrue($settings->isDepositsEnabled());
            self::assertTrue($choices->depositsEnabled());
            $deposit = $postings->post(
                '2026-04-30',
                'stall_post_booth_deposit(?::uuid, ?, ?, ?::date, ?::boolean)',
                [$stayId, 1500, 'Key', '2026-04-30', 'false'],
                $session,
            );
            self::assertNotSame('', $deposit);
            self::assertContains(['id' => $stayId, 'label' => 'Booth q' . $tag . ', Mona ' . $tag . ' (holding $15.00)'], $choices->depositsHeld());
        } finally {
            $pdo->rollBack();
        }
    }

    private function fees(Statements $statements, string $stayId): int
    {
        $rows = $statements->bound(
            "SELECT count(*) AS fees FROM journals WHERE source_type = 'late_fee' AND NOT is_reversal AND posting_key LIKE ?",
            ['late-fee:' . $stayId . '%']
        );

        return RowValue::integer($rows[0]['fees'] ?? null);
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
