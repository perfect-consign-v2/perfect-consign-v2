<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PDO;
use PerfectConsign\Feature\Dev\LocalAdmin;
use PerfectConsign\Shared\Jobs\StoreProvisionJob;
use PerfectConsign\Shared\Mail\MailOutbox;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeServer;
use PHPUnit\Framework\TestCase;

/**
 * Public signup through the real web server: the form and its browser check,
 * the emailed link, the waiting page, the worker's build, and signing in.
 */
final class SignupPageTest extends TestCase
{
    private const REFUSED = 'That link is invalid or expired. Start again at Create your store.';

    private PDO $pdo;

    private mixed $previousSwitch;

    protected function setUp(): void
    {
        $this->pdo = LocalAdmin::open()->pdo();
        $switch = $this->pdo->query("SELECT value::text FROM platform.settings WHERE key = 'store_signup_enabled'");
        $this->previousSwitch = $switch === false ? false : $switch->fetchColumn();
        $this->pdo->exec('UPDATE platform.signup_rules SET proof_bits = 8');
        $this->pdo->exec("DELETE FROM platform.signup_attempts WHERE client_address = '127.0.0.1'");
        $this->pdo->exec("DELETE FROM platform.signup_challenges WHERE client_address = '127.0.0.1'");
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('RESET SESSION AUTHORIZATION');
        $this->pdo->exec('UPDATE platform.signup_rules SET proof_bits = 18');
        if (is_string($this->previousSwitch)) {
            $restore = $this->pdo->prepare("UPDATE platform.settings SET value = ?::jsonb WHERE key = 'store_signup_enabled'");
            $restore->execute([$this->previousSwitch]);
        }
        $this->pdo->exec("DELETE FROM platform.signup_attempts WHERE client_address = '127.0.0.1'");
        $this->pdo->exec("DELETE FROM platform.signup_challenges WHERE client_address = '127.0.0.1'");
    }

    public function testTheLiveSignupPageFollowsThePlatformSwitch(): void
    {
        $server = null;
        try {
            $this->switchTo(false);
            $server = ProbeServer::startOn(dirname(__DIR__, 2), 8099);
            $client = new FormClient($server->baseUrl());
            $off = $client->get('/signup');
            self::assertSame(200, $off->status);
            self::assertStringContainsString('Self-serve signup is turned off', $off->body);

            $this->switchTo(true);
            $on = $client->get('/signup');
            self::assertStringContainsString('name="store_name"', $on->body);
            self::assertSame(1, preg_match('/data-proof-seed="[0-9a-f]{32}"/', $on->body));
            self::assertStringContainsString('signup-proof.js', $on->body);

            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            self::assertStringContainsString(self::REFUSED, $client->get('/signup/verify/' . $token)->body);
            self::assertStringContainsString(self::REFUSED, $client->post('/signup/verify/' . $token, [])->body);
        } finally {
            $server?->stop();
        }
    }

    public function testALiveSignupWaitsForTheLinkThenTheWorkerBuildsTheStore(): void
    {
        $name = 'sig' . bin2hex(random_bytes(3));
        $email = $name . '@example.test';
        $server = null;
        try {
            $this->switchTo(true);
            if (file_put_contents(ProbeServer::mailFile(), '') === false) {
                self::fail('the mail file could not be cleared');
            }
            $server = ProbeServer::startOn(dirname(__DIR__, 2), 8101);
            $client = new FormClient($server->baseUrl());
            $short = $client->post('/signup', $this->form($client->get('/signup')->body, $name, $email, 'short'));
            self::assertStringContainsString('Password must be at least 8 characters.', $short->body);

            $sent = $client->post('/signup', $this->form($short->body, $name, $email, 'chosen-password'));
            self::assertStringContainsString('Check your email for a link to finish setting up your store.', $sent->body);
            self::assertSame(0, $this->rowsIn("SELECT count(*) FROM platform.tenants WHERE slug = '" . $name . "'"));
            $mail = (string) file_get_contents(ProbeServer::mailFile());
            self::assertSame(1, preg_match('#/signup/verify/([A-Za-z0-9_-]{43})#', $mail, $link));

            $page = $client->get('/signup/verify/' . $link[1]);
            self::assertStringContainsString('Set up my store', $page->body);
            $waiting = $client->post('/signup/verify/' . $link[1], []);
            self::assertStringContainsString('<meta http-equiv="refresh" content="5">', $waiting->body);
            self::assertStringContainsString('Your store is being set up.', $waiting->body);

            $this->buildAsTheWorker($email, $server->baseUrl());
            $ready = $client->get('/signup/verify/' . $link[1]);
            self::assertStringContainsString('Your store is ready.', $ready->body);
            self::assertStringContainsString('value="' . $email . '"', $ready->body);
            self::assertSame(1, $this->rowsIn(
                "SELECT count(*) FROM platform.mail_outbox WHERE purpose = 'store-ready' AND address = '" . $email . "'"
            ));
            $signedIn = $client->signInByEmail($email, 'chosen-password');
            self::assertStringContainsString('/' . $name . '/', $signedIn->effectiveUrl);
        } finally {
            $server?->stop();
            $this->clear($name, $email);
        }
    }

    /**
     * The signup form's fields, with the browser check solved the way the
     * page's script solves it.
     *
     * @return array<string, string>
     */
    private function form(string $page, string $name, string $email, string $password): array
    {
        self::assertSame(1, preg_match('/data-proof-seed="([0-9a-f]{32})"/', $page, $seed));
        self::assertSame(1, preg_match('/data-proof-bits="([0-9]+)"/', $page, $bits));
        $nonce = 0;
        while ((hexdec(substr(hash('sha256', $seed[1] . ':' . $nonce), 0, 8)) >> (32 - (int) $bits[1])) !== 0) {
            $nonce++;
        }

        return [
            'store_name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirm' => $password,
            'proof_seed' => $seed[1],
            'proof_nonce' => (string) $nonce,
        ];
    }

    private function buildAsTheWorker(string $email, string $origin): void
    {
        $job = $this->pdo->prepare(
            "SELECT j.payload ->> 'signup' FROM platform.jobs j
             JOIN platform.store_signups s ON s.id::text = j.payload ->> 'signup'
             WHERE j.kind = 'store-provision' AND s.email = ?"
        );
        $job->execute([$email]);
        $signup = $job->fetchColumn();
        self::assertIsString($signup);
        $this->pdo->exec('SET SESSION AUTHORIZATION stall_worker');
        try {
            (new StoreProvisionJob($this->pdo, new MailOutbox($this->pdo), dirname(__DIR__, 2) . '/sql', $origin))->build($signup);
        } finally {
            $this->pdo->exec('RESET SESSION AUTHORIZATION');
        }
    }

    private function switchTo(bool $enabled): void
    {
        $this->pdo->exec(
            "UPDATE platform.settings SET value = '" . ($enabled ? 'true' : 'false') . "'::jsonb WHERE key = 'store_signup_enabled'"
        );
    }

    private function rowsIn(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    private function clear(string $slug, string $email): void
    {
        require_once dirname(__DIR__, 2) . '/bin/wipe-tenant.php';
        wipeTenant($this->pdo, $slug, $slug);
        $sends = $this->pdo->prepare(
            "DELETE FROM platform.jobs WHERE kind = 'outbound-mail'
             AND payload ->> 'mail' IN (SELECT id::text FROM platform.mail_outbox WHERE address = ?)"
        );
        $sends->execute([$email]);
        $builds = $this->pdo->prepare(
            "DELETE FROM platform.jobs WHERE kind = 'store-provision'
             AND payload ->> 'signup' IN (SELECT id::text FROM platform.store_signups WHERE email = ?)"
        );
        $builds->execute([$email]);
        foreach (['platform.mail_outbox WHERE address = ?', 'platform.store_signups WHERE email = ?'] as $where) {
            $delete = $this->pdo->prepare('DELETE FROM ' . $where);
            $delete->execute([$email]);
        }
    }
}
