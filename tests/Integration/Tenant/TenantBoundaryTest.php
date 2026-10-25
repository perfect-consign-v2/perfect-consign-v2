<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Tenant;

use PDO;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\SaleCeiling;
use PerfectConsign\Feature\Dev\LocalAdmin;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Mail\MailProgress;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;

final class TenantBoundaryTest extends TestCase
{
    public function testAnOverrideWorksUnderThePosterRole(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = LocalAdmin::open()->pdo();
        $schema = $this->schema($pdo, ProbeMall::SLUG);
        $role = $this->role($pdo, ProbeMall::SLUG, $schema);
        $tenantId = $this->tenantId($pdo, ProbeMall::SLUG);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $pdo->beginTransaction();
        try {
            $cashier = $this->staff($pdo, 'cash', 0);
            $manager = $this->staff($pdo, 'mgr', 10000);
            $pdo->exec('SET ROLE ' . $role);
            $ceiling = new SaleCeiling(
                new Statements($pdo),
                $this->actor(ProbeMall::SLUG, $tenantId, $cashier['id'], 'cashier'),
                new AuditLog(new Statements($pdo)),
            );
            $ceiling->discount(5000, $manager['username'], 'manager-pass-word', '01900000-0000-7000-8000-00000000ce01');
            for ($i = 0; $i < 5; $i++) {
                try {
                    $ceiling->discount(5000, $manager['username'], 'wrong-password', '01900000-0000-7000-8000-00000000ce01');
                    self::fail('a bad override was accepted');
                } catch (DatabaseRefusal $error) {
                    self::assertSame('a second person has to sign this', $error->getMessage());
                }
            }
            try {
                $ceiling->discount(5000, $manager['username'], 'manager-pass-word', '01900000-0000-7000-8000-00000000ce02');
                self::fail('a locked override was accepted');
            } catch (DatabaseRefusal $error) {
                self::assertSame('too many sign-in attempts', $error->getMessage());
            }
        } finally {
            $pdo->exec('RESET ROLE');
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        $pdo->beginTransaction();
        $pdo->exec('SET ROLE ' . $role);
        try {
            $pdo->exec('DELETE FROM override_sign_in_failures');
            self::fail('a poster delete of override failures was accepted');
        } catch (\PDOException) {
            self::assertTrue(true);
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('RESET ROLE');
    }

    public function testAPosterCannotTouchAnotherMallOrTheOwnerLockout(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = LocalAdmin::open()->pdo();
        $schema = $this->schema($pdo, ProbeMall::SLUG);
        $role = $this->role($pdo, ProbeMall::SLUG, $schema);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $pdo->exec('SET ROLE ' . $role);
        try {
            try {
                $pdo->query('SELECT id FROM platform.tenants LIMIT 1');
                self::fail('a poster select of platform.tenants was accepted');
            } catch (\PDOException) {
                self::assertTrue(true);
            }
            $pdo->exec('RESET ROLE');
            $pdo->beginTransaction();
            $pdo->exec('SET ROLE ' . $role);
            try {
                $pdo->exec('DELETE FROM platform.owner_sign_in_failures');
                self::fail('a poster delete of owner failures was accepted');
            } catch (\PDOException) {
                self::assertTrue(true);
            }
            $pdo->exec('ROLLBACK');
            $pdo->beginTransaction();
            $pdo->exec('SET ROLE ' . $role);
            try {
                $pdo->prepare(
                    "INSERT INTO platform.jobs (tenant, kind, payload, run_at)
                     VALUES ('mall', 'sale-notice', jsonb_build_object('date', '2099-12-01'), clock_timestamp())"
                )->execute();
                self::fail('a poster insert into platform.jobs was accepted');
            } catch (\PDOException) {
                self::assertTrue(true);
            }
            $pdo->exec('ROLLBACK');
            $other = $pdo->query(
                "SELECT nsp.nspname
                 FROM pg_catalog.pg_namespace AS nsp
                 WHERE nsp.nspname ~ '^tenant_[0-9a-f]{32}$'
                   AND nsp.nspname <> " . $pdo->quote($schema) . '
                 LIMIT 1'
            );
            $otherSchema = $other === false ? false : $other->fetchColumn();
            if (is_string($otherSchema) && $otherSchema !== '') {
                $pdo->beginTransaction();
                $pdo->exec('SET ROLE ' . $role);
                try {
                    $pdo->exec(
                        'INSERT INTO ' . $otherSchema . '.job_mail_sent (kind, mail_date, email)
                         VALUES (\'vendor-digest\', \'2099-01-01\', \'leak@example.test\')'
                    );
                    self::fail('a poster insert into another mall mail log was accepted');
                } catch (\PDOException) {
                    self::assertTrue(true);
                }
                $pdo->exec('ROLLBACK');
            }
        } finally {
            $pdo->exec('RESET ROLE');
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public function testMailProgressIsOneRowPerRecipient(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = LocalAdmin::open()->pdo();
        $schema = $this->schema($pdo, ProbeMall::SLUG);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $pdo->beginTransaction();
        try {
            $statements = new Statements($pdo);
            $progress = new MailProgress();
            self::assertFalse($progress->sent($statements, 'vendor-digest', '2099-03-01', 'ada@example.test'));
            $progress->record($statements, 'vendor-digest', '2099-03-01', 'ada@example.test');
            $progress->record($statements, 'vendor-digest', '2099-03-01', 'ada@example.test');
            self::assertTrue($progress->sent($statements, 'vendor-digest', '2099-03-01', 'ada@example.test'));
            $count = $pdo->query(
                "SELECT count(*) FROM job_mail_sent
                 WHERE kind = 'vendor-digest' AND mail_date = '2099-03-01' AND email = 'ada@example.test'"
            );
            self::assertSame(1, (int) ($count === false ? 0 : $count->fetchColumn()));
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public function testALetterIsEnqueuedForTheCurrentMallOnly(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = LocalAdmin::open()->pdo();
        $schema = $this->schema($pdo, ProbeMall::SLUG);
        $role = $this->role($pdo, ProbeMall::SLUG, $schema);
        $letter = '01900000-0000-7000-8000-00000000c301';
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $pdo->beginTransaction();
        try {
            $pdo->exec('SET ROLE ' . $role);
            $queue = new \PerfectConsign\Shared\Jobs\JobQueue();
            $queue->enqueueLetter($pdo, '2099-06-01', $letter);
            $queue->enqueueLetter($pdo, '2099-06-02', $letter);
            $pdo->exec('RESET ROLE');
            $count = $pdo->prepare(
                "SELECT count(*) FROM platform.jobs
                 WHERE tenant = ? AND kind = 'vendor-letter' AND payload ->> 'letter' = ?"
            );
            $count->execute([ProbeMall::SLUG, $letter]);
            self::assertSame(1, (int) $count->fetchColumn());
        } finally {
            $pdo->exec('RESET ROLE');
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /**
     * @return array{id: string, username: string}
     */
    private function staff(PDO $pdo, string $prefix, int $ceiling): array
    {
        $username = $prefix . bin2hex(random_bytes(3));
        $hash = password_hash('manager-pass-word', PASSWORD_DEFAULT);
        $role = str_starts_with($prefix, 'cash') ? 'cashier' : 'books';
        $statement = $pdo->prepare(
            'INSERT INTO staff (username, display_name, password_hash, role, discount_ceiling_bps, tax_free_ceiling_minor)
             VALUES (?, ?, ?, ?, ?, 0)
             RETURNING id::text'
        );
        $statement->execute([$username, ucfirst($prefix), $hash, $role, $ceiling]);
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

    private function schema(PDO $pdo, string $slug): string
    {
        $statement = $pdo->prepare('SELECT schema_name FROM platform.tenants WHERE slug = ?');
        $statement->execute([$slug]);
        $schema = $statement->fetchColumn();
        if (!is_string($schema) || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            self::fail($slug . ' schema is missing');
        }

        return $schema;
    }

    private function role(PDO $pdo, string $slug, string $schema): string
    {
        return \PerfectConsign\Shared\Tenant\TenantRecord::roleFor($slug, $schema);
    }

    private function tenantId(PDO $pdo, string $slug): string
    {
        $statement = $pdo->prepare('SELECT id::text FROM platform.tenants WHERE slug = ?');
        $statement->execute([$slug]);
        $id = $statement->fetchColumn();
        if (!is_string($id)) {
            self::fail($slug . ' id is missing');
        }

        return $id;
    }
}
