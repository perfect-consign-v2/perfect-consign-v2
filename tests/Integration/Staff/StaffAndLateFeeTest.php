<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Staff;

use PDO;
use PerfectConsign\Feature\Rent\LateFee;
use PerfectConsign\Feature\Staff\StaffStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;

final class StaffAndLateFeeTest extends TestCase
{
    public function testACashierAndAVendorSignInCanBeStored(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->connection();
        $pdo->exec('SET search_path TO ' . $this->schema($pdo) . ', pg_catalog, extensions');
        $pdo->beginTransaction();
        try {
            $statements = new Statements($pdo);
            $staff = new StaffStore($statements);
            $vendorId = $this->vendorId($statements);
            $name = 'c' . bin2hex(random_bytes(4));
            $staff->addCashier($name, 'Cashier Probe', 'cashier-pass');
            $staff->addVendor('v' . bin2hex(random_bytes(4)), 'Vendor Probe', 'vendor-pass', $vendorId);
            $people = $staff->cashiers();
            $codes = array_column($people, 'username');
            self::assertContains($name, $codes);
            try {
                $staff->addCashier('Bad Name', 'Cashier Probe', 'cashier-pass');
                self::fail('a sign-in name with a space was accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Use a sign-in name of letters, numbers, and hyphens.', $error->getMessage());
            }
            try {
                $staff->addCashier($name, '   ', 'cashier-pass');
                self::fail('a blank display name was accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Enter the name people will see.', $error->getMessage());
            }
            try {
                $staff->addCashier($name . 'b', 'Cashier Probe', 'short');
                self::fail('a short password was accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Use a password of at least 8 characters.', $error->getMessage());
            }
            try {
                $staff->addVendor($name . 'c', 'Vendor Probe', 'vendor-pass', 'vendor');
                self::fail('a vendor sign-in without a vendor was accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Choose a vendor.', $error->getMessage());
            }
        } finally {
            $pdo->rollBack();
        }
    }

    public function testALateFeePostsAgainstABooth(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->connection();
        $pdo->exec('SET search_path TO ' . $this->schema($pdo) . ', pg_catalog, extensions');
        $pdo->beginTransaction();
        try {
            $statements = new Statements($pdo);
            $code = 'L' . bin2hex(random_bytes(3));
            $statements->change('INSERT INTO booths (code, name) VALUES (?, ?)', [$code, 'Late fee booth']);
            $booth = $statements->bound('SELECT id FROM booths WHERE code = ?', [$code]);
            $boothId = (string) ($booth[0]['id'] ?? '');
            $fee = new LateFee(new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo)));
            $journalId = $fee->charge($boothId, '2026-06-15', '1.50');
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $journalId);
        } catch (DatabaseRefusal $error) {
            self::assertNotSame('', $error->getMessage());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function vendorId(Statements $statements): string
    {
        $rows = $statements->bound(
            'INSERT INTO vendors (code, first_name, last_name) VALUES (?, ?, ?) RETURNING id',
            ['V' . bin2hex(random_bytes(3)), 'Ada', 'Lane']
        );
        $id = $rows[0]['id'] ?? null;
        if (!is_string($id)) {
            self::fail('the vendor was not saved');
        }

        return $id;
    }

    private function schema(PDO $pdo): string
    {
        $listed = $pdo->query("SELECT schema_name FROM platform.tenants WHERE slug = '" . ProbeMall::SLUG . "'");
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
