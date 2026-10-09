<?php

declare(strict_types=1);

require_once __DIR__ . '/provision-tenant.php';

final class TenantProof
{
    private const SCHEMA_A = 'tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SCHEMA_B = 'tenant_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const ROLE_A = 'poster_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const ROLE_B = 'poster_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function __construct(
        private readonly PDO $pdo,
        private readonly TenantProvisioner $provisioner,
        private readonly string $sqlDirectory,
    ) {
    }

    public function run(): void
    {
        $this->ensureMall();
        $this->runFile('prove_app.sql');
        $this->dropRoles();
        $this->createRoles();
        try {
            $script = str_replace(
                ['-- LEDGER_A', '-- LEDGER_B'],
                [$this->ledger(self::SCHEMA_A, self::ROLE_A), $this->ledger(self::SCHEMA_B, self::ROLE_B)],
                $this->proofSql()
            );
            $this->pdo->exec($script);
            $this->assertRolledBack();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        } finally {
            $this->dropRoles();
        }
    }

    private function ledger(string $schema, string $role): string
    {
        $files = TenantProvisioner::ledgerFiles();
        $sql = 'SET search_path TO ' . $schema . ', pg_catalog, extensions;';
        foreach ($files as $file) {
            $path = $this->sqlDirectory . DIRECTORY_SEPARATOR . $file;
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('could not read ' . $file);
            }
            $sql .= "\n" . $this->provisioner->rewrite($contents, $schema, $role);
        }

        return $sql;
    }

    private function runFile(string $file): void
    {
        $path = $this->sqlDirectory . DIRECTORY_SEPARATOR . $file;
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('could not read ' . $file);
        }
        $this->pdo->exec($sql);
    }

    private function proofSql(): string
    {
        $path = $this->sqlDirectory . DIRECTORY_SEPARATOR . 'prove_tenant.sql';
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('could not read prove_tenant.sql');
        }

        return $sql;
    }

    private function ensureMall(): void
    {
        $existing = $this->pdo->prepare('SELECT 1 FROM platform.tenants WHERE slug = ?');
        $existing->execute(['mall']);
        if ($existing->fetchColumn() !== false) {
            return;
        }
        $this->provisioner->provision('mall', 'Mall');
    }

    private function createRoles(): void
    {
        foreach ([self::ROLE_A, self::ROLE_B] as $role) {
            $this->pdo->exec(sprintf(
                'CREATE ROLE %s NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE',
                $role
            ));
        }
    }

    private function dropRoles(): void
    {
        foreach ([self::ROLE_A, self::ROLE_B] as $role) {
            $this->pdo->exec(sprintf('DROP ROLE IF EXISTS %s', $role));
        }
    }

    private function assertRolledBack(): void
    {
        $statement = $this->pdo->query(
            "SELECT count(*) AS tenants, "
            . "(SELECT count(*) FROM pg_namespace WHERE nspname IN ("
            . "'" . self::SCHEMA_A . "', '" . self::SCHEMA_B . "')) AS schemas "
            . "FROM platform.tenants WHERE slug IN ('proof-a', 'proof-b')"
        );
        if ($statement === false) {
            throw new RuntimeException('could not check the rolled-back tenants');
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (string) ($row['tenants'] ?? '') !== '0' || (string) ($row['schemas'] ?? '') !== '0') {
            throw new RuntimeException('the tenant proof left a schema or a tenant row');
        }
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    require_once __DIR__ . '/load-env.php';
    loadStallEnv(dirname(__DIR__) . '/.env');
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

    $pdo = new PDO(
        'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    try {
        $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql';
        (new TenantProof($pdo, new TenantProvisioner($pdo, $directory), $directory))->run();
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
