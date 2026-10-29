<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use PDO;
use PerfectConsign\Schema\MigrationFileChanged;
use PerfectConsign\Schema\SchemaCatalog;
use PerfectConsign\Schema\SchemaMigrator;
use PerfectConsign\Schema\TenantBaselineMismatch;
use RuntimeException;

final class ProbeMall
{
    public const SLUG = 'webprobe';

    public const USERNAME = 'owner';

    public const PASSWORD = 'web-probe-owner';

    public const EMAIL = 'owner@webprobe.test';

    public const DISPLAY_NAME = 'Web Probe';

    public const PERIOD_START = '2020-01-01';

    public const PERIOD_END = '2035-12-31';

    public const POSTING_DATE = '2026-06-15';

    public const MONTH = '2026-06';

    public static function ensure(string $root): void
    {
        require_once $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'load-env.php';
        loadStallEnv($root . DIRECTORY_SEPARATOR . '.env');
        self::fill('STALL_DB_HOST', '127.0.0.1');
        self::fill('STALL_DB_PORT', '5436');
        self::fill('STALL_DB_NAME', 'consign');
        self::fill('STALL_DB_USER', 'stall');
        self::fill('STALL_DB_PASSWORD', 'stall');
        self::requireDatabaseEnv();
        $attempts = 0;
        while (true) {
            try {
                self::bringCurrent($root);

                return;
            } catch (RuntimeException $error) {
                if (!str_contains($error->getMessage(), 'a migration is already running') || $attempts >= 50) {
                    throw $error;
                }
                $attempts++;
                usleep(100_000);
            }
        }
    }

    private static function bringCurrent(string $root): void
    {
        $migrator = new SchemaMigrator(self::admin(), new SchemaCatalog($root . DIRECTORY_SEPARATOR . 'sql'));
        $migrator->migratePlatform();
        if (!self::exists()) {
            self::run($root, 'bin' . DIRECTORY_SEPARATOR . 'provision-tenant.php', [self::SLUG, self::DISPLAY_NAME]);
        } else {
            self::current($root, $migrator);
            self::admin()->prepare('UPDATE platform.tenants SET display_name = ? WHERE slug = ?')->execute([
                self::DISPLAY_NAME,
                self::SLUG,
            ]);
        }
        self::bringTenants($migrator);
        $migrator->migratePlatformAfter();
        putenv('STALL_OWNER_PASSWORD=' . self::PASSWORD);
        $_ENV['STALL_OWNER_PASSWORD'] = self::PASSWORD;
        putenv('STALL_OWNER_EMAIL=' . self::EMAIL);
        $_ENV['STALL_OWNER_EMAIL'] = self::EMAIL;
        self::run($root, 'bin' . DIRECTORY_SEPARATOR . 'set-owner.php', [self::SLUG, self::USERNAME]);
        self::resetMallMoney();
    }

    /**
     * HTTP suites flip prepaid/late-fee switches. A hung test must not leave the rent page on the debtors book.
     */
    public static function resetMallMoney(): void
    {
        $pdo = self::admin();
        $schemaStmt = $pdo->prepare('SELECT schema_name FROM platform.tenants WHERE slug = ?');
        $schemaStmt->execute([self::SLUG]);
        $schema = $schemaStmt->fetchColumn();
        if (!is_string($schema) || $schema === '' || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            return;
        }
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $pdo->exec(
            'UPDATE mall
             SET rent_prepaid_from_prior_sales = false,
                 late_fees_enabled = false,
                 late_fee_grace_days = 0,
                 late_fee_first_minor = 0,
                 late_fee_next_minor = 0'
        );
    }

    private static function bringTenants(SchemaMigrator $migrator): void
    {
        $listed = self::admin()->query('SELECT slug FROM platform.tenants ORDER BY slug');
        if ($listed === false) {
            return;
        }
        while (($slug = $listed->fetchColumn()) !== false) {
            if (!is_string($slug) || $slug === '') {
                continue;
            }
            try {
                $migrator->migrateTenant($slug);
            } catch (MigrationFileChanged | TenantBaselineMismatch) {
                continue;
            } catch (RuntimeException $error) {
                if (str_contains($error->getMessage(), 'older schema')) {
                    continue;
                }
                throw $error;
            }
        }
    }

    public static function forgetSignIns(string $username): void
    {
        $statement = self::admin()->prepare(
            'DELETE FROM platform.owner_sign_in_failures
             WHERE username = ?
               AND tenant_id = (SELECT id FROM platform.tenants WHERE slug = ?)'
        );
        $statement->execute([$username, self::SLUG]);
    }

    public static function forgetEmailSignIns(string $email): void
    {
        $statement = self::admin()->prepare(
            'DELETE FROM platform.email_sign_in_failures WHERE email = ?'
        );
        $statement->execute([strtolower(trim($email))]);
    }

    private static function current(string $root, SchemaMigrator $migrator): void
    {
        try {
            $migrator->migrateTenant(self::SLUG);
        } catch (MigrationFileChanged | TenantBaselineMismatch) {
            self::replace($root);
        } catch (RuntimeException $error) {
            if (!str_contains($error->getMessage(), 'older schema')) {
                throw $error;
            }
            self::replace($root);
        }
    }

    private static function replace(string $root): void
    {
        require_once $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'wipe-tenant.php';
        $pdo = self::admin();
        self::clearUsedPasswordResets($pdo, self::SLUG);
        \wipeTenant($pdo, self::SLUG, self::SLUG);
        self::run($root, 'bin' . DIRECTORY_SEPARATOR . 'provision-tenant.php', [self::SLUG, self::DISPLAY_NAME]);
    }

    /**
     * Used password resets are append-only and block wipe forever. Local probe DBs run as
     * superuser so the suite can clear them for this slug only before wipeTenant.
     */
    private static function clearUsedPasswordResets(PDO $pdo, string $slug): void
    {
        $super = $pdo->query(
            "SELECT rolsuper FROM pg_roles WHERE rolname = current_user"
        );
        if ($super === false || $super->fetchColumn() !== true) {
            return;
        }
        $tenant = $pdo->prepare('SELECT id::text FROM platform.tenants WHERE slug = ?');
        $tenant->execute([$slug]);
        $tenantId = $tenant->fetchColumn();
        if (!is_string($tenantId) || $tenantId === '') {
            return;
        }
        $pdo->exec('SET event_triggers TO off');
        try {
            $pdo->exec('ALTER TABLE platform.password_resets DISABLE TRIGGER password_resets_append_only');
            $pdo->exec('ALTER TABLE platform.password_resets DISABLE TRIGGER password_resets_no_truncate');
            $pdo->prepare('DELETE FROM platform.password_resets WHERE tenant_id = ?::uuid')->execute([$tenantId]);
            $pdo->exec('ALTER TABLE platform.password_resets ENABLE ALWAYS TRIGGER password_resets_append_only');
            $pdo->exec('ALTER TABLE platform.password_resets ENABLE ALWAYS TRIGGER password_resets_no_truncate');
        } finally {
            $pdo->exec('SET event_triggers TO on');
        }
    }

    private static function fill(string $name, string $value): void
    {
        $current = getenv($name);
        if (is_string($current) && $current !== '') {
            return;
        }
        putenv($name . '=' . $value);
    }

    private static function requireDatabaseEnv(): void
    {
        foreach (['STALL_DB_HOST', 'STALL_DB_PORT', 'STALL_DB_NAME', 'STALL_DB_USER', 'STALL_DB_PASSWORD', 'STALL_APP_PASSWORD'] as $name) {
            $value = getenv($name);
            if (!is_string($value) || $value === '') {
                throw new RuntimeException($name . ' is required to run the web probe');
            }
        }
    }

    private static function exists(): bool
    {
        $statement = self::admin()->prepare('SELECT 1 FROM platform.tenants WHERE slug = ?');
        $statement->execute([self::SLUG]);

        return $statement->fetchColumn() !== false;
    }

    private static function admin(): PDO
    {
        return new PDO(
            'pgsql:host=' . self::env('STALL_DB_HOST') . ';port=' . self::env('STALL_DB_PORT') . ';dbname=' . self::env('STALL_DB_NAME'),
            self::env('STALL_DB_USER'),
            self::env('STALL_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            throw new RuntimeException($name . ' is required to run the web probe');
        }

        return $value;
    }

    /**
     * @param list<string> $arguments
     */
    private static function run(string $root, string $script, array $arguments): void
    {
        $command = array_merge([PHP_BINARY, $root . DIRECTORY_SEPARATOR . $script], $arguments);
        $pipes = [];
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('could not run ' . $script);
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $detail = trim(($stderr === false ? '' : $stderr) . ' ' . ($stdout === false ? '' : $stdout));
            throw new RuntimeException($script . ' failed: ' . $detail);
        }
    }
}
