<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Feature;

use PDO;
use PerfectConsign\Feature\Dev\LocalAdmin;
use PerfectConsign\Feature\Signup\SignupAdmin;
use PerfectConsign\Feature\Signup\SignupMail;
use PerfectConsign\Feature\Signup\SignupToken;
use PerfectConsign\Feature\Signup\StoreSignup;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Jobs\PlatformJobs;
use PerfectConsign\Shared\Jobs\StoreProvisionJob;
use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\MailOutbox;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformMailbox;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PerfectConsign\Tests\Support\QuietAudit;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A public signup from the form to a built store: the web role asks and
 * confirms, the worker builds the store as stall_provisioner, and the store
 * it builds has the same grants as one a superuser builds.
 */
final class StoreSignupFlowTest extends TestCase
{
    private const ORIGIN = 'https://signup.example.test';

    private PDO $pdo;

    private string $run;

    private string $mailFile;

    private mixed $previousMail;

    private mixed $previousSwitch;

    protected function setUp(): void
    {
        $this->pdo = LocalAdmin::open()->pdo();
        $this->run = bin2hex(random_bytes(4));
        $this->mailFile = dirname(__DIR__, 3) . '/var/test-signup-mail-' . $this->run . '.txt';
        $this->previousMail = getenv('STALL_MAIL_FILE');
        putenv('STALL_MAIL_FILE=' . $this->mailFile);
        $switch = $this->pdo->query("SELECT value::text FROM platform.settings WHERE key = 'store_signup_enabled'");
        $this->previousSwitch = $switch === false ? false : $switch->fetchColumn();
        $this->pdo->exec("UPDATE platform.settings SET value = 'true'::jsonb WHERE key = 'store_signup_enabled'");
        $this->pdo->exec('UPDATE platform.signup_rules SET proof_bits = 8');
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('RESET ROLE');
        $this->pdo->exec('RESET SESSION AUTHORIZATION');
        $this->pdo->exec('UPDATE platform.signup_rules SET proof_bits = 18');
        if (is_string($this->previousSwitch)) {
            $restore = $this->pdo->prepare("UPDATE platform.settings SET value = ?::jsonb WHERE key = 'store_signup_enabled'");
            $restore->execute([$this->previousSwitch]);
        }
        $this->clear();
        if (is_string($this->previousMail) && $this->previousMail !== '') {
            putenv('STALL_MAIL_FILE=' . $this->previousMail);
        } else {
            putenv('STALL_MAIL_FILE');
        }
        if (is_file($this->mailFile)) {
            unlink($this->mailFile);
        }
        $folder = dirname(__DIR__, 3) . '/var/test-signup-sql-' . $this->run;
        if (is_file($folder . '/001_tenant.sql')) {
            unlink($folder . '/001_tenant.sql');
        }
        if (is_dir($folder)) {
            rmdir($folder);
        }
    }

    public function testASignupWaitsForTheLinkThenTheWorkerBuildsTheStoreOnce(): void
    {
        $email = $this->email('owner');
        $tenants = $this->rowsIn('SELECT count(*) FROM platform.tenants');
        $this->asWeb();
        $signup = $this->signup();
        self::assertTrue($this->ask($signup, $email, 'Flow ' . $this->run));
        self::assertSame($tenants, $this->rowsIn('SELECT count(*) FROM platform.tenants'));
        $token = $this->linkFor($email);
        self::assertSame('pending', $signup->state($token)['status'] ?? null);
        self::assertSame('verified', $signup->verify($token));
        self::assertSame('verified', $signup->verify($token));
        $this->pdo->exec('RESET ROLE');

        $job = $this->jobFor($email);
        $this->asWorker();
        $build = new StoreProvisionJob($this->pdo, new MailOutbox($this->pdo), dirname(__DIR__, 3) . '/sql', self::ORIGIN);
        $build->build($job);
        $build->build($job);
        self::assertSame('stall_worker', $this->value('SELECT current_user'));
        $this->pdo->exec('RESET SESSION AUTHORIZATION');

        $row = $this->row(
            'SELECT s.status, s.slug, s.password_hash IS NULL AS gone, o.email_verified_at IS NOT NULL AS confirmed,
                    n.nspowner::regrole::text AS builder
             FROM platform.store_signups s
             JOIN platform.owners o ON o.tenant_id = s.tenant_id
             JOIN platform.tenants t ON t.id = s.tenant_id
             JOIN pg_namespace n ON n.nspname = t.schema_name
             WHERE s.email = ?',
            [$email]
        );
        self::assertSame(['ready', 'flow-' . $this->run, true, true, 'stall_provisioner'], array_values($row));
        self::assertSame(1, $this->rowsIn(
            "SELECT count(*) FROM platform.mail_outbox WHERE purpose = 'store-ready' AND address = '" . $email . "'"
        ));
        $this->asWeb();
        self::assertSame('ready', $signup->state($token)['status'] ?? null);
        self::assertSame('flow-' . $this->run, $signup->state($token)['slug'] ?? null);
        self::assertTrue($this->ask($signup, $email, 'Again ' . $this->run));
        self::assertStringContainsString('You already have a store', $this->mail());
        $this->pdo->exec('RESET ROLE');

        $this->assertSameGrantsAsASuperuserStore('flow-' . $this->run);
    }

    public function testTheWebRoleIsToldEachRefusalInPlainWords(): void
    {
        $this->asWeb();
        $signup = $this->signup();
        $this->refused(
            'Your browser did not finish its check. Try again.',
            fn () => $signup->request('Shop', $this->email('bad'), 'password1', 'password1', '198.51.100.90', str_repeat('0', 32), '0', self::ORIGIN)
        );
        $this->refused(
            'Enter a store name.',
            fn () => $signup->request(' ', $this->email('bad'), 'password1', 'password1', '198.51.100.90', '', '', self::ORIGIN)
        );
        for ($i = 0; $i < 5; $i++) {
            $this->ask($signup, $this->email('many' . $i), 'Many', '198.51.100.91');
        }
        $this->refused(
            'Too many signup attempts. Try again later.',
            fn () => $this->ask($signup, $this->email('many9'), 'Many', '198.51.100.91')
        );
        self::assertSame(24, $signup->pendingHours());
        self::assertNull($signup->state('short'));
        self::assertNull($signup->verify('short'));
        self::assertNull($signup->state(SignupToken::issue()['token']));
        self::assertNull($signup->verify(SignupToken::issue()['token']));
        $this->pdo->exec('RESET ROLE');
        $this->pdo->exec("UPDATE platform.settings SET value = 'false'::jsonb WHERE key = 'store_signup_enabled'");
        $this->asWeb();
        self::assertFalse($signup->request('Shop', $this->email('off'), 'password1', 'password1', '198.51.100.92', '', '', self::ORIGIN));
    }

    public function testAnOperatorQueuesResendsAndDiscards(): void
    {
        $this->pdo->exec(
            "INSERT INTO platform.admins (email, password_hash)
             VALUES ('signup-operator@example.test', 'not-a-password')
             ON CONFLICT (email) DO NOTHING"
        );
        $operator = $this->row('SELECT id::text AS id, email FROM platform.admins WHERE email = ?', ['signup-operator@example.test']);
        self::assertIsString($operator['id'] ?? null);
        $admin = ['id' => (string) $operator['id'], 'email' => (string) $operator['email']];
        $this->asWeb();
        $signups = $this->operator();
        $made = $this->email('made');
        self::assertSame($made, $signups->create('Made ' . $this->run, ' ' . strtoupper($made) . ' ', 'password1', 'password1', $admin));
        $this->refused(
            'A store for that email is already being set up.',
            fn () => $signups->create('Twice', $made, 'password1', 'password1', $admin)
        );
        $waiting = $this->email('waiting');
        $this->ask($this->signup(), $waiting, 'Waiting', '198.51.100.93');
        $listed = array_column($signups->listed(), null, 'email');
        self::assertTrue($listed[$made]['by_operator']);
        self::assertSame('verified', $listed[$made]['status']);
        self::assertSame('pending', $listed[$waiting]['status']);
        $first = $this->linkFor($waiting);
        self::assertSame($waiting, $signups->resend($listed[$waiting]['id'], self::ORIGIN, $admin, 24));
        self::assertNotSame($first, $this->linkFor($waiting));
        $this->refused(
            'Only a signup waiting for its link can get a new one.',
            fn () => $signups->resend($listed[$made]['id'], self::ORIGIN, $admin, 24)
        );
        self::assertSame($waiting, $signups->discard($listed[$waiting]['id'], $admin));
        $this->refused(
            'Only a signup that is waiting or failed can be discarded.',
            fn () => $signups->discard($listed[$waiting]['id'], $admin)
        );
        $this->refused('That signup was not found.', fn () => $signups->discard('not-a-uuid', $admin));
        self::assertGreaterThanOrEqual(0, $signups->reapedLately());
        self::assertIsArray($signups->unconfirmedStores());
        $this->pdo->exec('RESET ROLE');
    }

    public function testAFailedBuildIsRecordedAndTheCleanUpRemovesOldLinks(): void
    {
        $email = $this->email('late');
        $this->asWeb();
        $this->ask($this->signup(), $email, 'Late ' . $this->run, '198.51.100.94');
        $this->pdo->exec('RESET ROLE');
        $this->pdo->exec('ALTER TABLE platform.store_signups DISABLE TRIGGER store_signups_guard');
        $expire = $this->pdo->prepare("UPDATE platform.store_signups SET expires_at = clock_timestamp() - interval '1 minute' WHERE email = ?");
        $expire->execute([$email]);
        $this->pdo->exec('ALTER TABLE platform.store_signups ENABLE ALWAYS TRIGGER store_signups_guard');
        $this->asWorker();
        (new PlatformJobs($this->pdo, new StoreProvisionJob($this->pdo, new MailOutbox($this->pdo), dirname(__DIR__, 3) . '/sql', self::ORIGIN)))->reapSignups();
        $this->pdo->exec('RESET SESSION AUTHORIZATION');
        self::assertSame(0, $this->rowsIn("SELECT count(*) FROM platform.store_signups WHERE email = '" . $email . "'"));

        $broken = $this->email('broken');
        $this->asWeb();
        $signup = $this->signup();
        $this->ask($signup, $broken, 'Broken ' . $this->run, '198.51.100.95');
        $signup->verify($this->linkFor($broken));
        $this->pdo->exec('RESET ROLE');
        $job = $this->jobFor($broken);
        $this->asWorker();
        $installer = $this->brokenInstaller();
        $build = new StoreProvisionJob($this->pdo, new MailOutbox($this->pdo), $installer, self::ORIGIN);
        try {
            $build->build($job);
            self::fail('a build without its installer finished');
        } catch (RuntimeException $error) {
            self::assertNotSame('', $error->getMessage());
        }
        self::assertSame('stall_worker', $this->value('SELECT current_user'));
        $this->pdo->exec('RESET SESSION AUTHORIZATION');
        self::assertSame('failed', $this->value("SELECT status FROM platform.store_signups WHERE email = '" . $broken . "'"));
        self::assertSame(0, $this->rowsIn("SELECT count(*) FROM platform.tenants WHERE slug = 'broken-" . $this->run . "'"));
        $this->asWorker();
        $this->refused('the signup is missing', fn () => $build->build('01900000-0000-7000-8000-0000000000ff'));
        $this->pdo->exec('RESET SESSION AUTHORIZATION');
    }

    /** A folder whose store installer stops partway, so the build fails after it began. */
    private function brokenInstaller(): string
    {
        $folder = dirname(__DIR__, 3) . '/var/test-signup-sql-' . $this->run;
        if (!is_dir($folder)) {
            mkdir($folder);
        }
        file_put_contents($folder . '/001_tenant.sql', "CREATE TABLE ledger_probe (id integer);\nSELECT 1 / 0;\n");

        return $folder;
    }

    private function assertSameGrantsAsASuperuserStore(string $slug): void
    {
        $reference = 'ref' . $this->run;
        (new \PerfectConsign\Schema\TenantProvisioner($this->pdo, dirname(__DIR__, 3) . '/sql'))->provision($reference, 'Reference');
        $grants = function (string $slug): array {
            $statement = $this->pdo->prepare(
                "SELECT replace(replace(acl::text, t.schema_name, 'S'), 'poster_' || substr(t.schema_name, 8), 'P')
                 FROM platform.tenants t
                 CROSS JOIN LATERAL (
                     SELECT c.relname || ':' || coalesce(c.relacl::text, '') AS acl
                     FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                     WHERE n.nspname = t.schema_name
                     UNION ALL
                     SELECT 'schema:' || coalesce(n.nspacl::text, '') FROM pg_namespace n WHERE n.nspname = t.schema_name
                 ) AS rows
                 WHERE t.slug = ?
                 ORDER BY 1"
            );
            $statement->execute([$slug]);

            return array_map(
                static fn (string $acl): string => (string) preg_replace('/(?<=[{,\/])(?:stall_provisioner|stall)(?=[=,}\/])/', 'OWNER', $acl),
                $statement->fetchAll(PDO::FETCH_COLUMN)
            );
        };
        self::assertSame($grants($reference), $grants($slug));
    }

    private function ask(StoreSignup $signup, string $email, string $name, string $address = '198.51.100.89'): bool
    {
        $challenge = $signup->challenge($address);
        $nonce = 0;
        while ((hexdec(substr(hash('sha256', $challenge['seed'] . ':' . $nonce), 0, 8)) >> (32 - $challenge['bits'])) !== 0) {
            $nonce++;
        }

        return $signup->request($name, $email, 'password1', 'password1', $address, $challenge['seed'], (string) $nonce, self::ORIGIN);
    }

    private function signup(): StoreSignup
    {
        return new StoreSignup($this->pdo, $this->mailer(), $this->audit(), $this->createStub(LoggerInterface::class));
    }

    private function operator(): SignupAdmin
    {
        return new SignupAdmin($this->pdo, $this->mailer(), $this->audit());
    }

    private function mailer(): SignupMail
    {
        $logger = $this->createStub(LoggerInterface::class);
        $notices = new PlatformOperatorNotice($logger);

        return new SignupMail(new PlatformMailbox(new OutboundMail($logger), new GmailSender($notices), $notices), $logger);
    }

    private function audit(): \PerfectConsign\Feature\Admin\AdminAudit
    {
        return QuietAudit::admin($this->pdo, new Request('POST', '/signup', [], [], '', self::ORIGIN, '198.51.100.89'));
    }

    private function refused(string $message, callable $work): void
    {
        try {
            $work();
            self::fail('expected: ' . $message);
        } catch (RuntimeException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }

    private function linkFor(string $email): string
    {
        $found = preg_match_all(
            '#To: ' . preg_quote($email, '#') . '\nSubject: Finish setting up your store\n\n[^\n]*\n\S+/signup/verify/([A-Za-z0-9_-]{43})\n#',
            $this->mail(),
            $links
        );
        self::assertGreaterThan(0, $found, 'no link was mailed to ' . $email);

        return (string) end($links[1]);
    }

    private function mail(): string
    {
        return is_file($this->mailFile) ? (string) file_get_contents($this->mailFile) : '';
    }

    private function jobFor(string $email): string
    {
        return (string) $this->value(
            "SELECT j.payload ->> 'signup' FROM platform.jobs j
             JOIN platform.store_signups s ON s.id::text = j.payload ->> 'signup'
             WHERE j.kind = 'store-provision' AND s.email = '" . $email . "'"
        );
    }

    private function email(string $who): string
    {
        return $who . '-' . $this->run . '@signup.example.test';
    }

    private function asWeb(): void
    {
        $this->pdo->exec('SET ROLE stall_app');
    }

    private function asWorker(): void
    {
        $this->pdo->exec('SET SESSION AUTHORIZATION stall_worker');
    }

    private function rowsIn(string $sql): int
    {
        return (int) $this->value($sql);
    }

    private function value(string $sql): mixed
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        return $statement->fetchColumn();
    }

    /**
     * @param list<string> $params
     * @return array<string, mixed>
     */
    private function row(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function clear(): void
    {
        require_once dirname(__DIR__, 3) . '/bin/wipe-tenant.php';
        $slugs = $this->pdo->prepare("SELECT slug FROM platform.tenants WHERE slug LIKE ? OR slug = ?");
        $slugs->execute(['%-' . $this->run . '%', 'ref' . $this->run]);
        foreach ($slugs->fetchAll(PDO::FETCH_COLUMN) as $slug) {
            if (is_string($slug)) {
                wipeTenant($this->pdo, $slug, $slug);
            }
        }
        $like = '%-' . $this->run . '@signup.example.test';
        $jobs = $this->pdo->prepare(
            "DELETE FROM platform.jobs WHERE kind = 'store-provision'
             AND payload ->> 'signup' IN (SELECT id::text FROM platform.store_signups WHERE email LIKE ?)"
        );
        $jobs->execute([$like]);
        $sends = $this->pdo->prepare(
            "DELETE FROM platform.jobs WHERE kind = 'outbound-mail'
             AND payload ->> 'mail' IN (SELECT id::text FROM platform.mail_outbox WHERE address LIKE ?)"
        );
        $sends->execute([$like]);
        $outbox = $this->pdo->prepare('DELETE FROM platform.mail_outbox WHERE address LIKE ?');
        $outbox->execute([$like]);
        $rows = $this->pdo->prepare('DELETE FROM platform.store_signups WHERE email LIKE ?');
        $rows->execute([$like]);
        $attempts = $this->pdo->prepare('DELETE FROM platform.signup_attempts WHERE email LIKE ? OR client_address LIKE ?');
        $attempts->execute([$like, '198.51.100.%']);
        $this->pdo->exec("DELETE FROM platform.signup_challenges WHERE client_address LIKE '198.51.100.%'");
    }
}
