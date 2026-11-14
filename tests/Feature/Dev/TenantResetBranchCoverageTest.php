<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Dev;

use PerfectConsign\Feature\Dev\DemoLoginEnsure;
use PerfectConsign\Feature\Dev\DevTenantReset;
use PerfectConsign\Feature\Dev\MallTenantReset;
use PerfectConsign\Feature\Dev\ShopTenantReset;
use PerfectConsign\Schema\TenantProvisioner;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class TenantResetBranchCoverageTest extends TestCase
{
    private const SCHEMA = 'tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testAnEmptyMallGetsAReplacementOwnerBeforeAnyWipe(): void
    {
        $this->requireOwnerPassword();
        $mall = new MallTenantReset();
        $this->withEnv('STALL_OWNER_EMAIL', 'zcov-owner@example.test', function () use ($mall): void {
            $owners = $this->invoke($mall, 'withOwner', $this->columnPdo(false), []);
            self::assertIsArray($owners);
            $owner = $owners[0] ?? null;
            self::assertIsArray($owner);
            self::assertSame('owner', $owner['username']);
        });
    }

    public function testAProgressStreamRefusesADirectory(): void
    {
        $mall = new MallTenantReset();
        $previous = set_error_handler(static function (int $severity): bool {
            return $severity === E_WARNING;
        });
        try {
            $mall->progressStream(sys_get_temp_dir());
            self::fail('a directory was opened as a progress stream');
        } catch (RuntimeException $error) {
            self::assertSame('could not open a progress stream', $error->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    public function testProgressIsBoundWhenTheServerHasNoStdout(): void
    {
        $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        if (!is_file($cgi)) {
            self::markTestSkipped('php-cgi is not beside the CLI binary');
        }
        $script = tempnam(sys_get_temp_dir(), 'pc-stdout-');
        if ($script === false) {
            self::fail('the stdout probe could not be created');
        }
        $root = dirname(__DIR__, 3);
        $written = file_put_contents(
            $script,
            "<?php\ndeclare(strict_types=1);\nrequire " . var_export($root . '/vendor/autoload.php', true) . ";\n"
            . "if (function_exists('xdebug_start_code_coverage')) {\n"
            . "    xdebug_start_code_coverage();\n"
            . "}\n"
            . "\$reset = new PerfectConsign\\Feature\\Dev\\MallTenantReset();\n"
            . "(new ReflectionMethod(\$reset, 'keepProgress'))->invoke(\$reset);\n"
            . "\$coverageDir = getenv('PC_COVERAGE');\n"
            . "if (is_string(\$coverageDir) && \$coverageDir !== '' && function_exists('xdebug_get_code_coverage')) {\n"
            . "    \$hits = [];\n"
            . "    foreach (xdebug_get_code_coverage() as \$file => \$lines) {\n"
            . "        if (!is_string(\$file) || !is_array(\$lines)) {\n"
            . "            continue;\n"
            . "        }\n"
            . "        \$kept = [];\n"
            . "        foreach (\$lines as \$line => \$hit) {\n"
            . "            if (is_int(\$hit) && \$hit > 0) {\n"
            . "                \$kept[(int) \$line] = \$hit;\n"
            . "            }\n"
            . "        }\n"
            . "        if (\$kept !== []) {\n"
            . "            \$hits[\$file] = \$kept;\n"
            . "        }\n"
            . "    }\n"
            . "    if (\$hits !== []) {\n"
            . "        \$dump = \$coverageDir . DIRECTORY_SEPARATOR . 'cgi-' . bin2hex(random_bytes(8)) . '.cov';\n"
            . "        file_put_contents(\$dump, serialize(\$hits));\n"
            . "    }\n"
            . "}\n"
            . "echo defined('STDOUT') ? 'defined' : 'missing';\n"
        );
        if ($written === false) {
            unlink($script);
            self::fail('the stdout probe could not be written');
        }
        $pipes = [];
        $process = proc_open(
            [$cgi, '-q', '-f', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root
        );
        if (!is_resource($process)) {
            unlink($script);
            self::fail('php-cgi did not start');
        }
        $output = is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        $error = is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $exit = proc_close($process);
        unlink($script);
        self::assertSame(0, $exit, is_string($error) ? $error : '');
        self::assertIsString($output);
        self::assertStringContainsString('defined', $output);
    }

    public function testDevAndShopRefuseAMissingSqlFile(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-missing-sql-' . bin2hex(random_bytes(4));
        if (!mkdir($root) && !is_dir($root)) {
            self::fail('the empty sql directory could not be created');
        }
        $dev = new DevTenantReset();
        $this->invoke($dev, 'requireTools');
        $provisioner = new \TenantProvisioner($this->columnPdo(false), $root);
        set_error_handler(static function (int $severity, string $message): bool {
            return $severity === E_WARNING && str_contains($message, 'tenant.sql');
        });
        try {
            $dev = new DevTenantReset();
            $dev->useSqlRoot($root);
            try {
                $this->invoke($dev, 'loadSql', $this->execPdo('none'), $provisioner, self::SCHEMA);
                self::fail('a missing dev sql file was accepted');
            } catch (RuntimeException $error) {
                self::assertSame('could not read dev_tenant.sql', $error->getMessage());
            }
            $shop = new ShopTenantReset();
            $shop->useSqlRoot($root);
            try {
                $this->invoke($shop, 'loadSql', $this->execPdo('none'), $provisioner, self::SCHEMA);
                self::fail('a missing shop sql file was accepted');
            } catch (RuntimeException $error) {
                self::assertSame('could not read shop_tenant.sql', $error->getMessage());
            }
        } finally {
            restore_error_handler();
            rmdir($root);
        }
    }

    public function testLocalOpenIsRefusedBeforeAnyMallIsTouched(): void
    {
        $this->withEnv('STALL_DB_PORT', '1', function (): void {
            if (getenv('STALL_DB_PORT') !== '1') {
                self::fail('refusing to reset while the database port is still 5436');
            }
            foreach ([new MallTenantReset(), new DevTenantReset(), new ShopTenantReset()] as $reset) {
                try {
                    $reset->load();
                    self::fail('a non-local reset continued');
                } catch (RuntimeException $error) {
                    self::assertSame('test data reset is only allowed on the local consign database', $error->getMessage());
                }
            }
        });
    }

    public function testMallOwnerRecoveryRefusesBadRowsAndATakenEmail(): void
    {
        $mall = new MallTenantReset();
        $this->invoke($mall, 'requireTools');
        $directory = $this->invoke($mall, 'sqlDirectory');
        self::assertIsString($directory);
        self::assertStringEndsWith('sql', $directory);
        $this->invoke($mall, 'keepProgress');
        $stream = $mall->progressStream();
        self::assertIsResource($stream);
        fclose($stream);

        $this->withEnv('STALL_OWNER_PASSWORD', null, function () use ($mall): void {
            try {
                $this->invoke($mall, 'replacementOwner', $this->columnPdo(false));
                self::fail('a replacement owner was built without a password');
            } catch (RuntimeException $error) {
                self::assertSame('STALL_OWNER_PASSWORD is required', $error->getMessage());
            }
        });
        $this->requireOwnerPassword();
        $this->withEnv('STALL_OWNER_EMAIL', null, function () use ($mall): void {
            self::assertNull($this->invoke($mall, 'ownerEmailFromEnv', null));
        });
        $this->withEnv('STALL_OWNER_EMAIL', '  Zcov-Owner@Example.TEST ', function () use ($mall): void {
            self::assertSame('zcov-owner@example.test', $this->invoke($mall, 'ownerEmailFromEnv', null));
            self::assertNull($this->invoke($mall, 'ownerEmailFromEnv', $this->columnPdo('1')));
            self::assertSame('zcov-owner@example.test', $this->invoke($mall, 'ownerEmailFromEnv', $this->columnPdo(false)));
            $owner = $this->invoke($mall, 'replacementOwner', $this->columnPdo(false));
            self::assertIsArray($owner);
            self::assertSame('owner', $owner['username']);
            self::assertSame('zcov-owner@example.test', $owner['email']);
            self::assertIsString($owner['password_hash']);
            self::assertTrue($owner['password_hash'] !== '');
        });

        $owners = $this->invoke($mall, 'owners', $this->queuedPdo([
            'skip',
            ['username' => 'owner', 'password_hash' => 'hash', 'email' => '  Owner@Example.TEST '],
            false,
        ]));
        self::assertSame([
            ['username' => 'owner', 'password_hash' => 'hash', 'email' => 'owner@example.test'],
        ], $owners);
        $blank = $this->invoke($mall, 'ownerRow', ['username' => 'owner', 'password_hash' => 'hash', 'email' => '   ']);
        self::assertIsArray($blank);
        self::assertNull($blank['email']);
        try {
            $this->invoke($mall, 'ownerRow', ['username' => '', 'password_hash' => 'hash']);
            self::fail('an empty mall owner was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('the mall owner row is invalid', $error->getMessage());
        }

        $restored = [];
        $this->invoke($mall, 'restoreOwner', $this->insertPdo(true, 1, $restored), [
            'username' => 'owner',
            'password_hash' => 'hash',
            'email' => 'owner@example.test',
        ]);
        self::assertSame(['owner', 'hash', null, null, MallTenantReset::SLUG], $restored);

        $kept = [];
        $this->invoke($mall, 'restoreOwner', $this->insertPdo(false, 1, $kept), [
            'username' => 'owner',
            'password_hash' => 'hash',
            'email' => 'owner@example.test',
        ]);
        self::assertSame('owner@example.test', $kept[2] ?? null);

        try {
            $this->invoke($mall, 'restoreOwner', $this->insertPdo(false, 0, $kept), [
                'username' => 'owner',
                'password_hash' => 'hash',
                'email' => null,
            ]);
            self::fail('a missing mall accepted an owner restore');
        } catch (RuntimeException $error) {
            self::assertSame('the mall owner could not be restored', $error->getMessage());
        }
    }

    public function testDevSqlLoadRefusesABadSchemaAClosedSchemaAndAFailedLoad(): void
    {
        $dev = new DevTenantReset();
        $this->invoke($dev, 'requireTools');
        $provisioner = new \TenantProvisioner($this->columnPdo(false), dirname(__DIR__, 3) . '/sql');
        try {
            $this->invoke($dev, 'loadSql', $this->execPdo('none'), $provisioner, 'public');
            self::fail('a public schema was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('schema name is not a tenant schema', $error->getMessage());
        }
        try {
            $this->invoke($dev, 'loadSql', $this->execPdo('search'), $provisioner, self::SCHEMA);
            self::fail('a closed dev schema was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('could not open the dev schema', $error->getMessage());
        }
        try {
            $this->invoke($dev, 'loadSql', $this->execPdo('load'), $provisioner, self::SCHEMA);
            self::fail('a failed dev load was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('could not load dev_tenant.sql', $error->getMessage());
        }
        $this->invoke($dev, 'loadSql', $this->execPdo('none'), $provisioner, self::SCHEMA);

        $owners = $this->invoke($dev, 'owners', $this->queuedPdo([
            'skip',
            ['username' => 'owner', 'password_hash' => 'hash'],
            false,
        ]));
        self::assertSame([['username' => 'owner', 'password_hash' => 'hash']], $owners);
        try {
            $this->invoke($dev, 'ownerRow', ['username' => 'owner', 'password_hash' => '']);
            self::fail('an empty dev owner was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('the dev owner row is invalid', $error->getMessage());
        }
        $captured = [];
        try {
            $this->invoke($dev, 'restoreOwner', $this->insertPdo(false, 0, $captured), [
                'username' => 'owner',
                'password_hash' => 'hash',
            ]);
            self::fail('a missing dev mall accepted an owner restore');
        } catch (RuntimeException $error) {
            self::assertSame('the dev owner could not be restored', $error->getMessage());
        }
        $this->invoke($dev, 'restoreOwner', $this->insertPdo(false, 1, $captured), [
            'username' => 'owner',
            'password_hash' => 'hash',
        ]);
    }

    public function testShopRebuildAndOwnerPlantStayOnTheStub(): void
    {
        $shop = new ShopTenantReset();
        try {
            $shop->rebuild($this->wipeStub());
            self::fail('a stubbed shop rebuild continued past provisioning');
        } catch (RuntimeException $error) {
            self::assertSame('stall_backup is missing. Apply sql/000_platform.sql first.', $error->getMessage());
        }

        $this->invoke($shop, 'requireTools');
        $provisioner = new \TenantProvisioner($this->columnPdo(false), dirname(__DIR__, 3) . '/sql');
        try {
            $this->invoke($shop, 'loadSql', $this->execPdo('none'), $provisioner, 'public');
            self::fail('a public shop schema was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('schema name is not a tenant schema', $error->getMessage());
        }
        try {
            $this->invoke($shop, 'loadSql', $this->execPdo('search'), $provisioner, self::SCHEMA);
            self::fail('a closed shop schema was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('could not open the shop schema', $error->getMessage());
        }
        try {
            $this->invoke($shop, 'loadSql', $this->execPdo('load'), $provisioner, self::SCHEMA);
            self::fail('a failed shop load was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('could not load shop_tenant.sql', $error->getMessage());
        }
        $this->invoke($shop, 'loadSql', $this->execPdo('none'), $provisioner, self::SCHEMA);

        $this->withEnv('STALL_OWNER_PASSWORD', null, function () use ($shop): void {
            try {
                $this->invoke($shop, 'owner', $this->columnPdo(false));
                self::fail('a shop owner was built without a password');
            } catch (RuntimeException $error) {
                self::assertSame('STALL_OWNER_PASSWORD is required', $error->getMessage());
            }
        });
        $this->requireOwnerPassword();
        $this->withEnv('STALL_SHOP_OWNER_EMAIL', null, function () use ($shop): void {
            self::assertSame(ShopTenantReset::OWNER_EMAIL, $this->invoke($shop, 'ownerEmail', $this->columnPdo(false)));
        });
        $this->withEnv('STALL_SHOP_OWNER_EMAIL', 'not-an-email', function () use ($shop): void {
            try {
                $this->invoke($shop, 'ownerEmail', $this->columnPdo(false));
                self::fail('a bad shop email was accepted');
            } catch (RuntimeException $error) {
                self::assertSame('STALL_SHOP_OWNER_EMAIL is not an email address', $error->getMessage());
            }
        });
        $this->withEnv('STALL_SHOP_OWNER_EMAIL', '  Zcov-Shop@Example.TEST ', function () use ($shop): void {
            self::assertSame('zcov-shop@example.test', $this->invoke($shop, 'ownerEmail', $this->columnPdo(false)));
            try {
                $this->invoke($shop, 'ownerEmail', $this->columnPdo('1'));
                self::fail('a taken shop email was accepted');
            } catch (RuntimeException $error) {
                self::assertSame('the shop owner email is already used on another sign-in', $error->getMessage());
            }
            $owner = $this->invoke($shop, 'owner', $this->columnPdo(false));
            self::assertIsArray($owner);
            self::assertSame('zcov-shop@example.test', $owner['email']);
        });

        $duplicate = new PDOException('duplicate');
        $duplicate->errorInfo = ['23505', null, 'duplicate'];
        try {
            $this->invoke($shop, 'restoreOwner', $this->throwingPdo($duplicate), [
                'username' => 'owner',
                'password_hash' => 'hash',
                'email' => 'zcov-shop@example.test',
            ]);
            self::fail('a duplicate shop email was stored');
        } catch (RuntimeException $error) {
            self::assertSame('the shop owner email is already used on another sign-in', $error->getMessage());
        }
        $other = new PDOException('locked');
        $other->errorInfo = [];
        try {
            $this->invoke($shop, 'restoreOwner', $this->throwingPdo($other), [
                'username' => 'owner',
                'password_hash' => 'hash',
                'email' => 'zcov-shop@example.test',
            ]);
            self::fail('a database error was swallowed');
        } catch (PDOException $error) {
            self::assertSame('locked', $error->getMessage());
        }
        $captured = [];
        try {
            $this->invoke($shop, 'restoreOwner', $this->insertPdo(false, 0, $captured), [
                'username' => 'owner',
                'password_hash' => 'hash',
                'email' => 'zcov-shop@example.test',
            ]);
            self::fail('a missing shop accepted an owner restore');
        } catch (RuntimeException $error) {
            self::assertSame('the shop owner could not be restored', $error->getMessage());
        }
    }

    public function testDemoLoginRefusesBadSecretsAndStillProvisionsOnAStub(): void
    {
        $demo = new DemoLoginEnsure();
        self::assertSame([], $demo->ensureAfterWipe($this->columnPdo(false), 'zcov'));

        $this->withEnv('STALL_OWNER_PASSWORD', null, function () use ($demo): void {
            try {
                $this->invoke($demo, 'ownerPassword');
                self::fail('an empty owner password was accepted');
            } catch (RuntimeException $error) {
                self::assertSame('STALL_OWNER_PASSWORD is required', $error->getMessage());
            }
        });
        $this->requireOwnerPassword();
        $loaded = $this->invoke($demo, 'ownerPassword');
        self::assertIsString($loaded);
        self::assertTrue($loaded !== '');

        $this->withEnv('STALL_OWNER_EMAIL', null, function () use ($demo): void {
            try {
                $this->invoke($demo, 'mallOwnerEmail');
                self::fail('an empty mall email was accepted');
            } catch (RuntimeException $error) {
                self::assertSame('STALL_OWNER_EMAIL is required', $error->getMessage());
            }
        });
        $this->withEnv('STALL_OWNER_EMAIL', 'not-an-email', function () use ($demo): void {
            try {
                $this->invoke($demo, 'mallOwnerEmail');
                self::fail('a bad mall email was accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('STALL_OWNER_EMAIL is not an email address', $error->getMessage());
            }
        });
        $this->withEnv('STALL_OWNER_EMAIL', str_repeat('a', 190) . '@example.test', function () use ($demo): void {
            try {
                $this->invoke($demo, 'mallOwnerEmail');
                self::fail('a long mall email was accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('STALL_OWNER_EMAIL is not an email address', $error->getMessage());
            }
        });

        $this->withEnv('STALL_SHOP_OWNER_EMAIL', 'not-an-email', function () use ($demo): void {
            try {
                $this->invoke($demo, 'shopOwnerEmail', $this->columnPdo(false));
                self::fail('a bad shop email was accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('STALL_SHOP_OWNER_EMAIL is not an email address', $error->getMessage());
            }
        });
        $this->withEnv('STALL_SHOP_OWNER_EMAIL', null, function () use ($demo): void {
            self::assertSame(ShopTenantReset::OWNER_EMAIL, $this->invoke($demo, 'shopOwnerEmail', $this->columnPdo(false)));
            try {
                $this->invoke($demo, 'shopOwnerEmail', $this->columnPdo('1'));
                self::fail('a taken shop email was accepted');
            } catch (RuntimeException $error) {
                self::assertSame('the shop owner email is already used on another sign-in', $error->getMessage());
            }
        });

        $provisioner = new TenantProvisioner($this->columnPdo(false), dirname(__DIR__, 3) . '/sql');
        try {
            $this->invoke($demo, 'loadModeSql', $this->execPdo('none'), $provisioner, 'public', 'mall', 'mall_tenant.sql');
            self::fail('a public schema was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('schema name is not a tenant schema', $error->getMessage());
        }
        $previous = null;
        $previous = set_error_handler(static function (
            int $severity,
            string $message,
            string $file = '',
            int $line = 0,
        ) use (&$previous): bool {
            if ($severity === E_WARNING && str_contains($message, 'missing-zcov.sql')) {
                return true;
            }
            if (is_callable($previous)) {
                return (bool) $previous($severity, $message, $file, $line);
            }

            return false;
        });
        try {
            $this->invoke($demo, 'loadModeSql', $this->execPdo('none'), $provisioner, self::SCHEMA, 'mall', 'missing-zcov.sql');
            self::fail('a missing sql file was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('could not read missing-zcov.sql', $error->getMessage());
        } finally {
            restore_error_handler();
        }
        try {
            $this->invoke($demo, 'loadMallSql', $this->execPdo('search'), $provisioner, self::SCHEMA);
            self::fail('a closed mall schema was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('could not open the mall schema', $error->getMessage());
        }
        try {
            $this->invoke($demo, 'loadShopSql', $this->execPdo('load'), $provisioner, self::SCHEMA);
            self::fail('a failed shop load was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('could not load shop_tenant.sql', $error->getMessage());
        }
        $this->invoke($demo, 'loadMallSql', $this->execPdo('none'), $provisioner, self::SCHEMA);
        $this->invoke($demo, 'loadShopSql', $this->execPdo('none'), $provisioner, self::SCHEMA);

        $this->withEnv('STALL_OWNER_EMAIL', 'zcov-mall@example.test', function () use ($demo): void {
            $this->withEnv('STALL_SHOP_OWNER_EMAIL', 'zcov-shop@example.test', function () use ($demo): void {
                try {
                    $demo->ensureAfterWipe($this->columnPdo(false), DemoLoginEnsure::MALL_SLUG);
                    self::fail('a missing mall was provisioned on a stub');
                } catch (RuntimeException $error) {
                    self::assertSame('stall_backup is missing. Apply sql/000_platform.sql first.', $error->getMessage());
                }
                $notes = $demo->ensure($this->plantablePdo(true));
                self::assertContains('mall owner saved', $notes);
                self::assertContains('shop owner saved', $notes);
                $shop = $demo->ensureAfterWipe($this->plantablePdo(true), DemoLoginEnsure::SHOP_SLUG);
                self::assertContains('shop owner saved', $shop);
            });
        });
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$args);
    }

    private function requireOwnerPassword(): void
    {
        $password = getenv('STALL_OWNER_PASSWORD');
        if (!is_string($password) || $password === '') {
            self::fail('STALL_OWNER_PASSWORD is required');
        }
    }

    private function columnPdo(mixed $column): PDO
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn($column);
        $statement->method('fetch')->willReturn(false);
        $statement->method('rowCount')->willReturn(1);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('query')->willReturn(false);
        $pdo->method('exec')->willReturn(0);

        return $pdo;
    }

    /**
     * @param list<mixed> $rows
     */
    private function queuedPdo(array $rows): PDO
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturnCallback(static function (mixed ...$ignored) use (&$rows): mixed {
            if ($rows === []) {
                return false;
            }

            return array_shift($rows);
        });
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        return $pdo;
    }

    /**
     * @param list<mixed> $captured
     */
    private function insertPdo(bool $taken, int $rowCount, array &$captured): PDO
    {
        $captured = [];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($taken, $rowCount, &$captured): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('rowCount')->willReturn($rowCount);
            if (str_contains($sql, 'slug <>')) {
                $statement->method('execute')->willReturn(true);
                $statement->method('fetchColumn')->willReturn($taken ? '1' : false);

                return $statement;
            }
            $statement->method('execute')->willReturnCallback(function (array $params) use (&$captured): bool {
                $captured = $params;

                return true;
            });

            return $statement;
        });

        return $pdo;
    }

    private function execPdo(string $failOn): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturnCallback(static function (string $sql) use ($failOn): int|false {
            if ($failOn === 'search' && str_contains($sql, 'search_path')) {
                return false;
            }
            if ($failOn === 'load' && !str_contains($sql, 'search_path')) {
                return false;
            }

            return 0;
        });

        return $pdo;
    }

    private function wipeStub(): PDO
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn(false);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn(false);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('exec')->willReturn(0);

        return $pdo;
    }

    private function throwingPdo(PDOException $error): PDO
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willThrowException($error);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        return $pdo;
    }

    private function plantablePdo(bool $exists): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($exists): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('rowCount')->willReturn(1);
            $statement->method('fetch')->willReturn(false);
            if (str_contains($sql, 'FROM platform.tenants WHERE slug')) {
                $statement->method('fetchColumn')->willReturn($exists ? 1 : false);

                return $statement;
            }
            $statement->method('fetchColumn')->willReturn(false);

            return $statement;
        });
        $pdo->method('query')->willReturn(false);
        $pdo->method('exec')->willReturn(0);

        return $pdo;
    }

    /**
     * @param callable(): void $run
     */
    private function withEnv(string $key, ?string $value, callable $run): void
    {
        $previous = getenv($key);
        if ($value === null) {
            putenv($key);
        } else {
            putenv($key . '=' . $value);
        }
        try {
            $run();
        } finally {
            if ($previous === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $previous);
            }
        }
    }
}
