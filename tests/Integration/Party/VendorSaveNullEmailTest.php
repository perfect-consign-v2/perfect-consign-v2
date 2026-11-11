<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Party;

use PDO;
use PerfectConsign\Feature\Party\AssignmentBook;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Party\VendorDraft;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\QuietAudit;
use PHPUnit\Framework\TestCase;

final class VendorSaveNullEmailTest extends TestCase
{
    public function testChangingExpiryWithNoEmailSaves(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $pdo->exec('SET search_path TO ' . $this->schema($pdo) . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $store = new PartyStore(
            $statements,
            QuietAudit::make($statements),
            new AssignmentBook($statements),
        );
        $pdo->beginTransaction();
        try {
            $id = $store->addVendor(new VendorDraft([
                'code' => 'nx' . substr(bin2hex(random_bytes(3)), 0, 6),
                'first_name' => 'No',
                'last_name' => 'Email',
                'phone' => '',
                'email' => '',
                'address' => '',
                'city' => '',
                'state' => '',
                'postal' => '',
                'agreement_starts_on' => '',
                'agreement_ends_on' => '',
                'tax_id' => '',
                'notes' => '',
                'tax_exempt' => '',
                'tax_exempt_number' => '',
                'business_name' => '',
                'expiry_action' => 'return',
            ], null));
            $current = $store->findVendor($id);
            self::assertNotNull($current);
            self::assertArrayHasKey('email', $current);
            self::assertNull($current['email']);
            $store->saveVendor($id, new VendorDraft([
                'code' => RowValue::string($current['code'] ?? null),
                'first_name' => RowValue::string($current['first_name'] ?? null),
                'last_name' => RowValue::string($current['last_name'] ?? null),
                'phone' => '',
                'email' => '',
                'address' => '',
                'city' => '',
                'state' => '',
                'postal' => '',
                'agreement_starts_on' => '',
                'agreement_ends_on' => '',
                'tax_id' => '',
                'notes' => '',
                'tax_exempt' => '',
                'tax_exempt_number' => '',
                'business_name' => '',
                'expiry_action' => 'donate',
            ], null));
            $saved = $store->findVendor($id);
            self::assertNotNull($saved);
            self::assertSame('donate', $saved['expiry_action'] ?? null);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
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
