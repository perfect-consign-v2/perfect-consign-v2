<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

final class TenantProvisioner
{
    /**
     * @return list<string>
     */
    public static function ledgerFiles(): array
    {
        return (new \PerfectConsign\Schema\SchemaCatalog(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql'
        ))->tenantFiles();
    }

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $sqlDirectory,
    ) {
    }

    public function provision(string $slug, string $displayName): string
    {
        $this->assertSlug($slug);
        if ($displayName !== trim($displayName) || $displayName === '') {
            throw new InvalidArgumentException('a tenant display name is required');
        }
        $this->assertBackupRole();

        $idStatement = $this->pdo->query('SELECT uuidv7() AS id');
        if ($idStatement === false) {
            throw new RuntimeException('could not create a tenant id');
        }
        $idRow = $idStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($idRow) || !is_string($idRow['id'] ?? null)) {
            throw new RuntimeException('could not create a tenant id');
        }
        $id = $idRow['id'];
        $schema = 'tenant_' . str_replace('-', '', $id);
        $this->assertSchema($schema);
        $role = $this->roleName($slug, $schema);

        $createdRole = false;
        if (!$this->roleExists($role)) {
            $this->pdo->exec(sprintf(
                'CREATE ROLE %s NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE',
                $role
            ));
            $createdRole = true;
        }

        try {
            $this->pdo->beginTransaction();
            $insert = $this->pdo->prepare(
                'INSERT INTO platform.tenants (id, slug, schema_name, display_name) VALUES (?, ?, ?, ?)'
            );
            $insert->execute([$id, $slug, $schema, $displayName]);
            $this->pdo->exec(sprintf('CREATE SCHEMA %s', $schema));
            $this->pdo->exec(sprintf('REVOKE ALL ON SCHEMA %s FROM PUBLIC', $schema));
            $this->applyLedger($schema, $role);
            $this->pdo->commit();
            $this->grantApp($role);
            $this->grantBackup($schema, $role);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($createdRole) {
                $this->pdo->exec(sprintf('DROP ROLE IF EXISTS %s', $role));
            }
            throw $error;
        }

        return $schema;
    }

    public function applyLedger(string $schema, string $role): void
    {
        $this->assertSchema($schema);
        $this->assertRole($role);
        $this->pdo->exec(sprintf('SET search_path TO %s, pg_catalog, extensions', $schema));
        foreach (self::ledgerFiles() as $file) {
            $path = $this->sqlDirectory . DIRECTORY_SEPARATOR . $file;
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new RuntimeException('could not read ' . $file);
            }
            $this->pdo->exec($this->rewrite($sql, $schema, $role));
        }
        (new \PerfectConsign\Schema\SchemaMigrator(
            $this->pdo,
            new \PerfectConsign\Schema\SchemaCatalog($this->sqlDirectory)
        ))->recordTenant($schema);
    }

    public function rewrite(string $sql, string $schema, string $role): string
    {
        return (new \PerfectConsign\Schema\SchemaCatalog($this->sqlDirectory))->rewrite($sql, $schema, $role);
    }

    public function grantApp(string $role): void
    {
        $this->assertRole($role);
        $exists = $this->pdo->query("SELECT 1 FROM pg_roles WHERE rolname = 'stall_app'");
        if ($exists === false || $exists->fetchColumn() === false) {
            throw new RuntimeException('stall_app is missing. Apply sql/015_stall_app.sql first.');
        }
        $this->pdo->exec(sprintf('GRANT %s TO stall_app', $role));
    }

    public function grantBackup(string $schema, string $role): void
    {
        $this->assertSchema($schema);
        $this->assertRole($role);
        $this->assertBackupRole();
        $this->pdo->exec(sprintf('GRANT USAGE ON SCHEMA platform TO %s', $role));
        $this->pdo->exec(sprintf(
            'GRANT EXECUTE ON FUNCTION platform.latest_owner_backup(uuid) TO %s',
            $role
        ));
        $this->pdo->exec(sprintf(
            'GRANT EXECUTE ON FUNCTION platform.record_owner_backup(uuid, uuid, bigint, text) TO %s',
            $role
        ));
        $this->pdo->exec(sprintf('GRANT USAGE ON SCHEMA %s TO stall_backup', $schema));
        $this->pdo->exec(sprintf('GRANT SELECT ON ALL TABLES IN SCHEMA %s TO stall_backup', $schema));
        $this->pdo->exec(sprintf('GRANT SELECT ON ALL SEQUENCES IN SCHEMA %s TO stall_backup', $schema));
    }

    public function roleName(string $slug, string $schema): string
    {
        $this->assertSlug($slug);
        $this->assertSchema($schema);
        if ($slug === 'mall') {
            return 'stall_poster';
        }

        return 'poster_' . substr($schema, strlen('tenant_'));
    }

    private function assertSlug(string $slug): void
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $slug) !== 1) {
            throw new InvalidArgumentException('a tenant slug must be a short lowercase name');
        }
    }

    private function assertSchema(string $schema): void
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new InvalidArgumentException('schema name is not a tenant schema');
        }
    }

    private function roleExists(string $role): bool
    {
        $this->assertRole($role);
        $exists = $this->pdo->prepare('SELECT 1 FROM pg_roles WHERE rolname = ?');
        $exists->execute([$role]);

        return $exists->fetchColumn() !== false;
    }

    private function assertRole(string $role): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new InvalidArgumentException('poster role name is invalid');
        }
    }

    private function assertBackupRole(): void
    {
        $exists = $this->pdo->query("SELECT 1 FROM pg_roles WHERE rolname = 'stall_backup'");
        if ($exists === false || $exists->fetchColumn() === false) {
            throw new RuntimeException('stall_backup is missing. Apply sql/018_owner_backup.sql first.');
        }
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $slug = $argv[1] ?? '';
    $displayName = $argv[2] ?? '';
    if (!is_string($slug) || $slug === '' || !is_string($displayName) || $displayName === '') {
        fwrite(STDERR, "usage: php bin/provision-tenant.php <slug> <display name>\n");
        exit(1);
    }

    $host = getenv('STALL_DB_HOST');
    $port = getenv('STALL_DB_PORT');
    $name = getenv('STALL_DB_NAME');
    $user = getenv('STALL_DB_USER');
    $password = getenv('STALL_DB_PASSWORD');
    if (
        !is_string($host) || $host === ''
        || !is_string($port) || $port === ''
        || !is_string($name) || $name === ''
        || !is_string($user) || $user === ''
        || !is_string($password) || $password === ''
    ) {
        fwrite(STDERR, "STALL_DB_HOST, STALL_DB_PORT, STALL_DB_NAME, STALL_DB_USER, and STALL_DB_PASSWORD are required\n");
        exit(1);
    }

    $pdo = new PDO(
        'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    try {
        $schema = (new TenantProvisioner($pdo, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql'))->provision($slug, $displayName);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
    fwrite(STDOUT, $schema . "\n");
}
