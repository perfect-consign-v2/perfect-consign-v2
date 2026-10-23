<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Password;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\CredentialStamp;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Feature\Password\LoginAccounts;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\PasswordRecovery;
use PerfectConsign\Feature\Password\ResetLetter;
use PerfectConsign\Feature\Password\ResetNotice;
use PerfectConsign\Feature\Password\ResetRequests;
use PerfectConsign\Feature\Password\ResetTokens;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PerfectConsign\Tests\Support\ProbeMall;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AppRoleStaffLookupTest extends TestCase
{
    public function testTheAppRoleReadsACashierOnlyWhileItIsTheMallPoster(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $admin = $this->admin();
        $tenant = $this->tenant($admin);
        $username = 'c' . bin2hex(random_bytes(4));
        $silent = 's' . bin2hex(random_bytes(4));
        $email = $username . '@example.com';
        $cashier = $this->cashier($admin, $tenant, $username, $email, 'cashier-pass');
        $quiet = $this->cashier($admin, $tenant, $silent, null, 'cashier-pass');
        $mail = tempnam(sys_get_temp_dir(), 'pc-mail-');
        if ($mail === false) {
            self::fail('the mail file could not be created');
        }
        $previous = getenv('STALL_MAIL_FILE');
        putenv('STALL_MAIL_FILE=' . $mail);
        $app = $this->app();
        try {
            $this->assertSchemaIsClosed($app, $tenant->schema);
            $accounts = new LoginAccounts($app, new DatabaseRole($app));
            $found = $accounts->find($tenant, $username);
            self::assertNotNull($found);
            self::assertSame('staff', $found->kind);
            self::assertSame($cashier, $found->id);
            self::assertSame($email, $found->email);
            $withoutEmail = $accounts->find($tenant, $silent);
            self::assertNotNull($withoutEmail);
            self::assertSame('', $withoutEmail->email);
            self::assertNull($accounts->find($tenant, 'missing-user'));
            $owner = $accounts->find($tenant, ProbeMall::USERNAME);
            self::assertNotNull($owner);
            self::assertSame('owner', $owner->kind);
            self::assertSame('stall_app', $this->currentUser($app));
            $this->assertSchemaIsClosed($app, $tenant->schema);

            $stamp = new CredentialStamp($app, $this->session($cashier, '1', 'cashier'), new DatabaseRole($app));
            self::assertTrue($stamp->matches($tenant));
            self::assertFalse((new CredentialStamp($app, $this->session($cashier, '2', 'cashier'), new DatabaseRole($app)))->matches($tenant));
            self::assertSame('stall_app', $this->currentUser($app));

            $recovery = $this->recovery($app);
            $recovery->request($tenant, 'missing-user', 'http://127.0.0.1:8097');
            $recovery->request($tenant, $silent, 'http://127.0.0.1:8097');
            self::assertSame('', $this->text($mail));
            $recovery->request($tenant, $username, 'http://127.0.0.1:8097');
            $token = $this->token($this->text($mail));
            self::assertTrue($recovery->available($tenant, $token));
            $recovery->redeem($tenant, $token, 'cashier-next', 'cashier-next');
            self::assertFalse($recovery->available($tenant, $token));
            try {
                $recovery->redeem($tenant, $token, 'cashier-other', 'cashier-other');
                self::fail('a used reset link was accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertSame(ResetNotice::EXPIRED, $error->getMessage());
            }
            self::assertFalse($stamp->matches($tenant));
            self::assertTrue(password_verify('cashier-next', $this->hash($admin, $tenant, $cashier)));
            self::assertFalse(password_verify('cashier-pass', $this->hash($admin, $tenant, $cashier)));
            self::assertSame('stall_app', $this->currentUser($app));
            $this->assertAnOwnerWithoutAnEmailIsNotSentToTheCompany($admin, $app, $recovery, $tenant, $mail);
        } finally {
            $this->restoreMail($previous);
            if (is_file($mail)) {
                unlink($mail);
            }
            $this->remove($admin, $tenant, [$cashier, $quiet], [$username, $silent, 'missing-user', ProbeMall::USERNAME]);
        }
    }

    private function assertSchemaIsClosed(PDO $pdo, string $schema): void
    {
        try {
            $pdo->query('SELECT 1 FROM ' . $schema . '.staff');
            self::fail('stall_app read the mall schema');
        } catch (PDOException $error) {
            self::assertSame('42501', $error->getCode());
            self::assertStringContainsString('permission denied for schema', $error->getMessage());
        }
    }

    private function recovery(PDO $pdo): PasswordRecovery
    {
        $roles = new DatabaseRole($pdo);
        $tokens = new ResetTokens($pdo);
        $logger = new NullLogger();

        return new PasswordRecovery(
            $pdo,
            $roles,
            new LoginAccounts($pdo, $roles),
            $tokens,
            new ResetRequests($pdo),
            new PasswordChoice(),
            new ResetLetter($tokens, new OutboundMail($logger), $logger),
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function session(string $account, string $version, string $role): Session
    {
        return new class($account, $version, $role) extends Session {
            public function __construct(
                private readonly string $account,
                private readonly string $version,
                private readonly string $actorRole,
            ) {
                parent::__construct(null, false);
            }

            public function get(string $key): mixed
            {
                return match ($key) {
                    'actor_account' => $this->account,
                    'credentials_version' => $this->version,
                    'actor_role' => $this->actorRole,
                    default => null,
                };
            }
        };
    }

    private function assertAnOwnerWithoutAnEmailIsNotSentToTheCompany(
        PDO $admin,
        PDO $app,
        PasswordRecovery $recovery,
        TenantRecord $tenant,
        string $mail,
    ): void {
        $company = 'owner-reset@example.com';
        $ownerEmail = $this->storedEmail(
            $admin,
            'SELECT email FROM platform.owners WHERE tenant_id = ?::uuid AND username = ?',
            [$tenant->id, ProbeMall::USERNAME],
        );
        $companyEmail = $this->storedEmail($admin, 'SELECT email FROM ' . $tenant->schema . '.mall', []);
        $this->change(
            $admin,
            'UPDATE platform.owners SET email = NULL WHERE tenant_id = ?::uuid AND username = ?',
            [$tenant->id, ProbeMall::USERNAME],
        );
        $this->change($admin, 'UPDATE ' . $tenant->schema . '.mall SET email = ?', [$company]);
        try {
            $before = $this->text($mail);
            self::assertTrue($recovery->request($tenant, ProbeMall::USERNAME, 'http://127.0.0.1:8097'));
            self::assertSame($before, $this->text($mail));
            self::assertStringNotContainsString('To: ' . $company . "\n", $this->text($mail));
            self::assertSame('stall_app', $this->currentUser($app));
        } finally {
            $this->change(
                $admin,
                'UPDATE platform.owners SET email = ? WHERE tenant_id = ?::uuid AND username = ?',
                [$ownerEmail, $tenant->id, ProbeMall::USERNAME],
            );
            $this->change($admin, 'UPDATE ' . $tenant->schema . '.mall SET email = ?', [$companyEmail]);
        }
    }

    /**
     * @param list<mixed> $params
     */
    private function storedEmail(PDO $pdo, string $sql, array $params): ?string
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }
        if (!is_string($value)) {
            self::fail('the email could not be read');
        }

        return $value;
    }

    /**
     * @param list<mixed> $params
     */
    private function change(PDO $pdo, string $sql, array $params): void
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
    }

    private function token(string $mail): string
    {
        $found = preg_match('#/reset/([A-Za-z0-9_-]{43})#', $mail, $matched);
        if ($found !== 1 || !isset($matched[1])) {
            self::fail('the reset link was not mailed');
        }

        return $matched[1];
    }

    private function text(string $path): string
    {
        $text = file_get_contents($path);

        return is_string($text) ? $text : '';
    }

    private function restoreMail(mixed $previous): void
    {
        if (is_string($previous) && $previous !== '') {
            putenv('STALL_MAIL_FILE=' . $previous);

            return;
        }
        putenv('STALL_MAIL_FILE');
    }

    private function cashier(PDO $pdo, TenantRecord $tenant, string $username, ?string $email, string $password): string
    {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        if ($hashed === false) {
            self::fail('the password hash could not be created');
        }
        $statement = $pdo->prepare(
            'INSERT INTO ' . $tenant->schema . '.staff (username, display_name, password_hash, role, email)
             VALUES (?, ?, ?, ?, ?)
             RETURNING id::text'
        );
        $statement->execute([$username, 'Lookup Cashier', $hashed, 'cashier', $email]);
        $id = $statement->fetchColumn();
        if (!is_string($id)) {
            self::fail('the cashier was not saved');
        }

        return $id;
    }

    private function hash(PDO $pdo, TenantRecord $tenant, string $id): string
    {
        $statement = $pdo->prepare('SELECT password_hash FROM ' . $tenant->schema . '.staff WHERE id = ?::uuid');
        $statement->execute([$id]);
        $hash = $statement->fetchColumn();
        if (!is_string($hash)) {
            self::fail('the cashier password was not stored');
        }

        return $hash;
    }

    /**
     * @param list<string> $ids
     * @param list<string> $usernames
     */
    private function remove(PDO $pdo, TenantRecord $tenant, array $ids, array $usernames): void
    {
        $staff = $pdo->prepare('DELETE FROM ' . $tenant->schema . '.staff WHERE id = ?::uuid');
        foreach ($ids as $id) {
            $staff->execute([$id]);
        }
        $requests = $pdo->prepare(
            'DELETE FROM platform.password_reset_requests WHERE tenant_id = ?::uuid AND username = ?'
        );
        foreach ($usernames as $username) {
            $requests->execute([$tenant->id, $username]);
        }
    }

    private function tenant(PDO $pdo): TenantRecord
    {
        $statement = $pdo->prepare(
            'SELECT id::text AS id, slug, schema_name, display_name FROM platform.tenants WHERE slug = ?'
        );
        $statement->execute([ProbeMall::SLUG]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            self::fail('webprobe is missing');
        }
        $id = $row['id'] ?? null;
        $slug = $row['slug'] ?? null;
        $schema = $row['schema_name'] ?? null;
        $name = $row['display_name'] ?? null;
        if (!is_string($id) || !is_string($slug) || !is_string($schema) || !is_string($name)) {
            self::fail('webprobe is missing');
        }

        return new TenantRecord($id, $slug, $schema, TenantRecord::roleFor($slug, $schema), $name);
    }

    private function currentUser(PDO $pdo): string
    {
        $user = $pdo->query('SELECT current_user');
        if ($user === false) {
            self::fail('the database role could not be read');
        }
        $name = $user->fetchColumn();
        if (!is_string($name)) {
            self::fail('the database role could not be read');
        }

        return $name;
    }

    private function admin(): PDO
    {
        return $this->connect($this->env('STALL_DB_USER'), $this->env('STALL_DB_PASSWORD'));
    }

    private function app(): PDO
    {
        return $this->connect('stall_app', $this->env('STALL_APP_PASSWORD'));
    }

    private function connect(string $user, string $password): PDO
    {
        return new PDO(
            'pgsql:host=' . $this->env('STALL_DB_HOST') . ';port=' . $this->env('STALL_DB_PORT') . ';dbname=' . $this->env('STALL_DB_NAME'),
            $user,
            $password,
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
