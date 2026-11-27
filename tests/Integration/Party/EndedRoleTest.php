<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Party;

use PDO;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Feature\Dev\DemoSubject;
use PerfectConsign\Feature\Party\AssignmentBook;
use PerfectConsign\Feature\Party\PartyRoleType;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Party\VendorDraft;
use PerfectConsign\Feature\WaitList\WaitListFields;
use PerfectConsign\Feature\WaitList\WaitListStore;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\QuietAudit;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Removing a wait-list entry or a seller ends that role, so the party page never forwards to a missing record. */
final class EndedRoleTest extends TestCase
{
    public function testRemovingAWaitListEntryEndsTheWaitListRole(): void
    {
        [$pdo, $statements, $parties] = $this->open();
        $pdo->beginTransaction();
        try {
            $waitList = new WaitListStore($statements, new WaitListFields(), new NullLogger());
            $tag = bin2hex(random_bytes(3));
            $partyId = $waitList->add('Wren', 'W' . $tag, 'wren-' . $tag . '@example.test', '555-0100', '10', '10', '');
            $listed = $parties->findParty($partyId);
            self::assertNotNull($listed);
            self::assertSame([PartyRoleType::WAIT_LIST], $listed['roles']);

            $waitList->remove($partyId);

            $removed = $parties->findParty($partyId);
            self::assertNotNull($removed, 'the person stays a party after leaving the wait list');
            self::assertSame([], $removed['roles']);
            self::assertSame('inactive', $this->roleStatus($pdo, $partyId, PartyRoleType::WAIT_LIST));
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public function testDeletingASellerEndsTheSellerRole(): void
    {
        [$pdo, , $parties] = $this->open();
        $pdo->beginTransaction();
        try {
            $vendorId = $parties->addVendor(new VendorDraft([
                'code' => 'er' . substr(bin2hex(random_bytes(3)), 0, 6),
                'first_name' => 'Ended',
                'last_name' => 'Role',
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
            $vendor = $parties->findVendor($vendorId);
            self::assertNotNull($vendor);
            $partyId = $vendor['party_id'] ?? null;
            self::assertIsString($partyId);
            self::assertSame('active', $this->roleStatus($pdo, $partyId, PartyRoleType::VENDOR));

            $parties->removeVendor($vendorId);

            self::assertNull($parties->vendorIdForParty($partyId));
            self::assertSame('inactive', $this->roleStatus($pdo, $partyId, PartyRoleType::VENDOR));
            $removed = $parties->findParty($partyId);
            self::assertNotNull($removed);
            self::assertNotContains(PartyRoleType::VENDOR, $removed['roles']);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /**
     * @return array{0: PDO, 1: Statements, 2: PartyStore}
     */
    private function open(): array
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $schema = $this->schema($pdo);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $session = new Session(null, false);
        DemoSubject::stamp($pdo, $schema, $session);
        $statements = new Statements($pdo);

        return [$pdo, $statements, new PartyStore(
            $statements,
            QuietAudit::journal($statements, new Actor($session), $session),
            new AssignmentBook($statements),
        )];
    }

    private function roleStatus(PDO $pdo, string $partyId, string $roleType): string
    {
        $statement = $pdo->prepare('SELECT status FROM party_roles WHERE party_id = ?::uuid AND role_type = ?');
        $statement->execute([$partyId, $roleType]);
        $status = $statement->fetchColumn();
        self::assertIsString($status);

        return $status;
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
