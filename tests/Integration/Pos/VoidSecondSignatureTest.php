<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Pos;

use PDO;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\SaleCeiling;
use PerfectConsign\Feature\Dev\LocalAdmin;
use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Feature\Pos\SaleReversal;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;

final class VoidSecondSignatureTest extends TestCase
{
    public function testCashierVoidWithoutOverrideLeavesTheSalePosted(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = LocalAdmin::open()->pdo();
        $schema = $this->schema($pdo);
        $tenantId = $this->tenantId($pdo);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $ledger = new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo));
        $pdo->beginTransaction();
        try {
            $statements->change(
                'UPDATE mall SET require_second_signature = true',
                []
            );
            $cashier = $this->staff($pdo, 'cash', 0);
            $date = $this->openDate($statements);
            $registerId = $ledger->newId();
            $sessionId = $ledger->newId();
            $vendorId = $ledger->newId();
            $itemId = $ledger->newId();
            $code = 'vd' . substr(str_replace('-', '', $itemId), -10);
            $statements->change(
                'INSERT INTO registers (id, name) VALUES (?::uuid, ?)',
                [$registerId, 'Void proof']
            );
            $statements->change(
                'INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
                 VALUES (?::uuid, ?::uuid, ?, ?::jsonb, 0)',
                [$sessionId, $registerId, 'Cash', '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}']
            );
            \PerfectConsign\Tests\Support\SeedVendor::withId(
                $statements,
                $vendorId,
                $code,
                'Void',
                'Proof',
                null,
                true,
                'TX-' . $code
            );
            $statements->change(
                'INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity, commission_bps)
                 VALUES (?::uuid, ?, ?::uuid, ?, 1000, ?, ?::date, 1, 5000)',
                [$itemId, $code, $vendorId, 'Void coat', 'BC-' . $code, $date]
            );
            $take = $statements->bound(
                'SELECT stall_half_up(1000, stall_commission_for(?::uuid, 1000, ?::date)) AS commission',
                [$itemId, $date]
            );
            $commissionMinor = (int) ($take[0]['commission'] ?? 0);
            $payableMinor = 1000 - $commissionMinor;
            $cash = $ledger->roleAccount('cash');
            $commission = $ledger->roleAccount('commission_revenue');
            $payable = $ledger->roleAccount('vendor_payable');
            $lines = [
                PostedLines::entry($cash, 1000, 0, null, null),
            ];
            if ($commissionMinor > 0) {
                $lines[] = PostedLines::entry($commission, 0, $commissionMinor, null, null);
            }
            if ($payableMinor > 0) {
                $lines[] = PostedLines::entry($payable, 0, $payableMinor, 'vendor', $vendorId);
            }
            $saleId = $ledger->post(new JournalDraft(
                postingKey: 'void-proof:' . $itemId,
                postingDate: $date,
                source: new JournalSource('sale', 'void-proof:' . $itemId, 'Void proof sale'),
                lines: $lines,
                sessionId: $sessionId,
                beforeCommit: function (string $journalId) use ($statements, $itemId, $commissionMinor): void {
                    $statements->change(
                        'INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, quantity, commission_minor, tax_minor)
                         VALUES (?::uuid, 1, ?::uuid, 1000, 1, ?, 0)',
                        [$journalId, $itemId, $commissionMinor]
                    );
                },
            ));
            $onHand = $this->quantity($statements, $itemId);
            $reversals = new SaleReversal(
                $ledger,
                $statements,
                new SaleCeiling(
                    $statements,
                    $this->actor(ProbeMall::SLUG, $tenantId, $cashier['id'], 'cashier'),
                    new AuditLog($statements),
                    $this->request(),
                ),
                new VendorClawback($ledger, $statements),
            );
            try {
                $reversals->voidSale($sessionId, $date, $saleId, '', '');
                self::fail('a cashier void without a second signature was accepted');
            } catch (DatabaseRefusal $refusal) {
                self::assertSame('a second person has to sign this', $refusal->getMessage());
            }
            self::assertFalse($ledger->reversed($saleId));
            self::assertSame($onHand, $this->quantity($statements, $itemId));
            $audit = $statements->bound(
                "SELECT count(*)::int AS n FROM audit_events WHERE action = 'sale.void' AND entity_id = ?",
                [$saleId]
            );
            self::assertSame(0, (int) ($audit[0]['n'] ?? -1));
            $reversal = $statements->bound(
                'SELECT id FROM journals WHERE posting_key = ?',
                ['reversal:' . $saleId]
            );
            self::assertSame([], $reversal);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function quantity(Statements $statements, string $itemId): int
    {
        $rows = $statements->bound('SELECT quantity FROM items WHERE id = ?::uuid', [$itemId]);

        return (int) ($rows[0]['quantity'] ?? -1);
    }

    private function openDate(Statements $statements): string
    {
        $rows = $statements->rows(
            "SELECT to_char(starts_on, 'YYYY-MM-DD') AS starts_on
             FROM accounting_periods
             WHERE status = 'open'
             ORDER BY starts_on
             LIMIT 1"
        );
        if ($rows !== [] && is_string($rows[0]['starts_on'] ?? null)) {
            return $rows[0]['starts_on'];
        }

        return '2096-03-01';
    }

    /**
     * @return array{id: string, username: string}
     */
    private function staff(PDO $pdo, string $prefix, int $ceiling): array
    {
        $username = $prefix . bin2hex(random_bytes(3));
        $hash = password_hash('manager-pass-word', PASSWORD_DEFAULT);
        $statement = $pdo->prepare(
            'INSERT INTO staff (username, display_name, password_hash, role, discount_ceiling_bps, tax_free_ceiling_minor)
             VALUES (?, ?, ?, ?, ?, 0)
             RETURNING id::text'
        );
        $statement->execute([$username, ucfirst($prefix), $hash, 'cashier', $ceiling]);
        $id = $statement->fetchColumn();
        if (!is_string($id)) {
            self::fail('staff was not saved');
        }

        return ['id' => $id, 'username' => $username];
    }

    private function actor(string $slug, string $tenantId, string $account, string $role): Actor
    {
        $now = (string) time();

        return new Actor($this->session([
            'owner_slug' => $slug,
            'tenant_id' => $tenantId,
            'actor_role' => $role,
            'actor_account' => $account,
            'signed_in_at' => $now,
            'last_seen_at' => $now,
        ]));
    }

    private function request(): Request
    {
        return new Request('POST', '/pos', [], [], '', '', '127.0.0.1');
    }

    /**
     * @param array<string, mixed> $bag
     */
    private function session(array $bag): Session
    {
        return new class ($bag) extends Session {
            /** @param array<string, mixed> $bag */
            public function __construct(private array $bag)
            {
                parent::__construct(null, false);
            }

            public function get(string $key): mixed
            {
                return $this->bag[$key] ?? null;
            }

            public function set(string $key, mixed $value): void
            {
                $this->bag[$key] = $value;
            }

            public function destroy(): void
            {
                $this->bag = [];
            }
        };
    }

    private function schema(PDO $pdo): string
    {
        $listed = $pdo->query(
            "SELECT schema_name FROM platform.tenants WHERE slug = '" . ProbeMall::SLUG . "'"
        );
        if ($listed === false) {
            self::fail('probe schema is missing');
        }
        $schema = $listed->fetchColumn();
        if (!is_string($schema) || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            self::fail('probe schema is missing');
        }

        return $schema;
    }

    private function tenantId(PDO $pdo): string
    {
        $listed = $pdo->query(
            "SELECT id::text FROM platform.tenants WHERE slug = '" . ProbeMall::SLUG . "'"
        );
        if ($listed === false) {
            self::fail('probe tenant is missing');
        }
        $id = $listed->fetchColumn();
        if (!is_string($id)) {
            self::fail('probe tenant is missing');
        }

        return $id;
    }
}
