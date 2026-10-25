<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PDO;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\ResetNotice;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\ProbeServer;
use PHPUnit\Framework\TestCase;

final class AdminPortalTest extends TestCase
{
    private const ADMIN_EMAIL = 'platform-admin@example.test';

    private const ADMIN_PASSWORD = 'admin-pass-1';

    public function testAdminPortalLoginListEnterAndDelete(): void
    {
        $root = dirname(__DIR__, 2);
        ProbeMall::ensure($root);
        $pdo = $this->adminPdo();
        $this->seedAdmin($pdo);
        $slug = 'admintest';
        $this->ensureDisposableTenant($root, $pdo, $slug);
        $server = ProbeServer::start($root);
        try {
            $client = new FormClient($server->baseUrl());
            $login = $client->get('/admin/login');
            self::assertSame(200, $login->status);
            self::assertStringContainsString('Platform admin', $login->body);

            $refused = $client->post('/admin/login', [
                'email' => self::ADMIN_EMAIL,
                'password' => 'wrong-password',
            ]);
            self::assertStringContainsString('That email or password is wrong.', $refused->body);

            $signedIn = $client->post('/admin/login', [
                'email' => self::ADMIN_EMAIL,
                'password' => self::ADMIN_PASSWORD,
            ]);
            self::assertSame(200, $signedIn->status);
            self::assertStringContainsString('Tenants', $signedIn->body);
            self::assertStringContainsString(ProbeMall::SLUG, $signedIn->body);
            self::assertStringContainsString($slug, $signedIn->body);

            $audit = $client->get('/admin/audit');
            self::assertStringContainsString('admin.sign_in', $audit->body);

            $enterOwner = $client->post('/admin/tenants/' . $slug . '/enter', [
                'role' => 'owner',
            ]);
            self::assertSame(200, $enterOwner->status);
            self::assertStringContainsString('Sell', $enterOwner->body);

            $back = $client->get('/admin/tenants');
            self::assertStringContainsString('Tenants', $back->body);
            $enterCashier = $client->post('/admin/tenants/' . $slug . '/enter', [
                'role' => 'cashier',
            ]);
            self::assertSame(200, $enterCashier->status);
            self::assertTrue(
                str_contains($enterCashier->body, 'Sell')
                || str_contains($enterCashier->body, 'register')
                || str_contains($enterCashier->body, 'Register')
                || str_contains($enterCashier->body, 'ticket')
            );

            $loggedOutMall = $client->post('/' . $slug . '/logout', []);
            self::assertStringContainsString('Signed out.', $loggedOutMall->body);
            $adminAfterTenantLogout = $client->getWithoutRedirect('/admin/tenants');
            self::assertTrue(
                in_array($adminAfterTenantLogout->status, [301, 302, 303], true)
                || str_contains($adminAfterTenantLogout->body, 'Platform admin')
                || str_contains($adminAfterTenantLogout->body, 'Sign in'),
                'admin should not stay signed in after tenant logout'
            );

            $client->get('/admin/login');
            $client->post('/admin/login', [
                'email' => self::ADMIN_EMAIL,
                'password' => self::ADMIN_PASSWORD,
            ]);
            $client->post('/admin/tenants/' . $slug . '/enter', ['role' => 'owner']);
            $client->post('/admin/logout', []);
            $mallAfterAdminLogout = $client->getWithoutRedirect('/' . $slug . '/dashboard');
            self::assertTrue(
                in_array($mallAfterAdminLogout->status, [301, 302, 303], true)
                || str_contains($mallAfterAdminLogout->body, 'Sign in')
                || str_contains($mallAfterAdminLogout->body, 'username'),
                'tenant session must end when admin logs out'
            );

            $client->get('/admin/login');
            $again = $client->post('/admin/login', [
                'email' => self::ADMIN_EMAIL,
                'password' => self::ADMIN_PASSWORD,
            ]);
            self::assertStringContainsString('Tenants', $again->body);
            $deleteForm = $client->get('/admin/tenants/' . $slug . '/delete');
            self::assertStringContainsString('permanently deletes', $deleteForm->body);
            $wrong = $client->post('/admin/tenants/' . $slug . '/delete', [
                'confirm_slug' => 'not-' . $slug,
            ]);
            self::assertStringContainsString('Type the store slug exactly', $wrong->body);

            $deleted = $client->post('/admin/tenants/' . $slug . '/delete', [
                'confirm_slug' => $slug,
            ]);
            self::assertStringContainsString('Deleted ' . $slug, $deleted->body);
            $still = $pdo->prepare('SELECT 1 FROM platform.tenants WHERE slug = ?');
            $still->execute([$slug]);
            self::assertFalse($still->fetchColumn() !== false);

            $client->get('/admin/forgot');
            $unknown = $client->post('/admin/forgot', ['email' => 'missing@example.test']);
            $known = $client->post('/admin/forgot', ['email' => self::ADMIN_EMAIL]);
            self::assertStringContainsString(ResetNotice::SENT, $unknown->body);
            self::assertStringContainsString(ResetNotice::SENT, $known->body);

            file_put_contents(ProbeServer::mailFile(), '');
            $client->postWithHeaders('/admin/forgot', ['email' => self::ADMIN_EMAIL], ['Host: evil.example']);
            $mail = file_get_contents(ProbeServer::mailFile());
            self::assertIsString($mail);
            self::assertStringNotContainsString('evil.example', $mail);
            self::assertStringContainsString($server->baseUrl() . '/admin/reset/', $mail);

            if (preg_match('#/admin/reset/([A-Za-z0-9_-]{43})#', $mail, $token) === 1) {
                $resetPage = $client->get('/admin/reset/' . $token[1]);
                self::assertStringContainsString('Reset password', $resetPage->body);
                self::assertStringContainsString('New password', $resetPage->body);
            }
        } finally {
            $server->stop();
            $this->cleanupDisposable($pdo, $slug);
        }
    }

    private function seedAdmin(PDO $pdo): void
    {
        $hash = (new PasswordChoice())->hash(self::ADMIN_PASSWORD);
        $statement = $pdo->prepare(
            'INSERT INTO platform.admins (id, email, password_hash)
             VALUES (uuidv7(), ?, ?)
             ON CONFLICT (email) DO UPDATE
                SET password_hash = EXCLUDED.password_hash,
                    credentials_version = platform.admins.credentials_version + 1'
        );
        $statement->execute([self::ADMIN_EMAIL, $hash]);
    }

    private function ensureDisposableTenant(string $root, PDO $pdo, string $slug): void
    {
        $found = $pdo->prepare('SELECT 1 FROM platform.tenants WHERE slug = ?');
        $found->execute([$slug]);
        if ($found->fetchColumn() !== false) {
            require_once $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'wipe-tenant.php';
            wipeTenant($pdo, $slug, $slug);
        }
        require_once $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'provision-tenant.php';
        $provisioner = new \TenantProvisioner($pdo, $root . DIRECTORY_SEPARATOR . 'sql');
        $schema = $provisioner->provision($slug, 'Admin Test');
        $hash = (new PasswordChoice())->hash('owner-pass-1');
        $owner = $pdo->prepare(
            'INSERT INTO platform.owners (id, tenant_id, username, password_hash, email)
             SELECT uuidv7(), id, ?, ?, ?
             FROM platform.tenants
             WHERE slug = ?
             ON CONFLICT (tenant_id) DO UPDATE
                SET username = EXCLUDED.username,
                    password_hash = EXCLUDED.password_hash'
        );
        $owner->execute(['owner', $hash, 'owner@example.test', $slug]);
        $role = \PerfectConsign\Shared\Tenant\TenantRecord::roleFor($slug, $schema);
        $pdo->exec('SET ROLE ' . $role);
        try {
            $cashier = $pdo->prepare(
                'INSERT INTO ' . $schema . '.staff (username, display_name, password_hash, role, email)
                 VALUES (?, ?, ?, \'cashier\', ?)'
            );
            $cashier->execute([
                'cashier',
                'Cashier One',
                (new PasswordChoice())->hash('cashier-pass-1'),
                'cashier@example.test',
            ]);
        } finally {
            $pdo->exec('RESET ROLE');
        }
    }

    private function cleanupDisposable(PDO $pdo, string $slug): void
    {
        $found = $pdo->prepare('SELECT 1 FROM platform.tenants WHERE slug = ?');
        $found->execute([$slug]);
        if ($found->fetchColumn() === false) {
            return;
        }
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'wipe-tenant.php';
        wipeTenant($pdo, $slug, $slug);
    }

    private function adminPdo(): PDO
    {
        $host = getenv('STALL_DB_HOST');
        $port = getenv('STALL_DB_PORT');
        $name = getenv('STALL_DB_NAME');
        $user = getenv('STALL_DB_USER');
        $password = getenv('STALL_DB_PASSWORD');
        if (!is_string($host) || $host === '') {
            $host = '127.0.0.1';
        }
        if (!is_string($port) || $port === '') {
            $port = '5436';
        }
        if (!is_string($name) || $name === '') {
            $name = 'consign';
        }
        if (!is_string($user) || $user === '') {
            $user = 'stall';
        }
        if (!is_string($password) || $password === '') {
            $password = 'stall';
        }

        return new PDO(
            'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
