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

    public const DISPLAY_NAME = 'Web Probe';

    public const PERIOD_START = '2020-01-01';

    public const PERIOD_END = '2035-12-31';

    public const POSTING_DATE = '2026-06-15';

    public const MONTH = '2026-06';

    public static function ensure(string $root): void
    {
        require_once $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'load-env.php';
        loadStallEnv($root . DIRECTORY_SEPARATOR . '.env');
        self::fill('STALL_DB_USER', 'stall');
        self::fill('STALL_DB_PASSWORD', 'stall');
        self::requireDatabaseEnv();
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
        putenv('STALL_OWNER_PASSWORD=' . self::PASSWORD);
        $_ENV['STALL_OWNER_PASSWORD'] = self::PASSWORD;
        self::run($root, 'bin' . DIRECTORY_SEPARATOR . 'set-owner.php', [self::SLUG, self::USERNAME]);
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
        \wipeTenant(self::admin(), self::SLUG, self::SLUG);
        self::run($root, 'bin' . DIRECTORY_SEPARATOR . 'provision-tenant.php', [self::SLUG, self::DISPLAY_NAME]);
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
