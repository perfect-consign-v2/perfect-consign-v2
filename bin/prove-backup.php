<?php

declare(strict_types=1);

require_once __DIR__ . '/provision-tenant.php';

use PerfectApp\Logger\FileLogger;
use PerfectConsign\Feature\Backup\BackupConnection;
use PerfectConsign\Feature\Backup\BackupWorkspace;
use PerfectConsign\Feature\Backup\CommandProcess;
use PerfectConsign\Feature\Backup\DirectoryTree;
use PerfectConsign\Feature\Backup\PlatformSnapshot;
use PerfectConsign\Feature\Backup\PostgresTools;
use PerfectConsign\Feature\Backup\SchemaDump;
use PerfectConsign\Feature\Backup\TenantBackup;

final class BackupProof
{
    private const SLUG = 'proof-backup';

    private const RESTORED = 'tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';

    public function __construct(
        private readonly PDO $pdo,
        private readonly TenantProvisioner $provisioner,
        private readonly TenantBackup $archives,
        private readonly PostgresTools $tools,
    ) {
    }

    public function run(): void
    {
        $schema = null;
        $role = null;
        $archive = null;
        try {
            $this->removeFixture(null);
            $schema = $this->provisioner->provision(self::SLUG, 'Backup Proof');
            $role = $this->provisioner->roleName(self::SLUG, $schema);
            $this->owner();
            $this->sale($schema);
            $archive = $this->archives->create(BackupConnection::stallBackup(), self::SLUG);
            $extracted = $archive->directory . DIRECTORY_SEPARATOR . 'out';
            if (!mkdir($extracted, 0700) && !is_dir($extracted)) {
                throw new RuntimeException('could not read the backup');
            }
            $zip = new ZipArchive();
            if ($zip->open($archive->path) !== true || !$zip->extractTo($extracted) || !$zip->close()) {
                throw new RuntimeException('could not read the backup');
            }
            $platform = file_get_contents($extracted . DIRECTORY_SEPARATOR . 'platform.sql');
            $schemaSql = file_get_contents($extracted . DIRECTORY_SEPARATOR . 'schema.sql');
            $manifest = file_get_contents($extracted . DIRECTORY_SEPARATOR . 'manifest.txt');
            if ($platform === false || $schemaSql === false || $manifest === false) {
                throw new RuntimeException('the backup is missing a file');
            }
            if (!str_contains($platform, self::SLUG)) {
                throw new RuntimeException('the backup left out the mall');
            }
            if (!preg_match('/^schema_sha256=([0-9a-f]{64})$/m', $manifest, $hash)) {
                throw new RuntimeException('the backup manifest has no schema hash');
            }
            $actual = hash('sha256', $schemaSql);
            if ($actual !== $hash[1]) {
                throw new RuntimeException('the schema hash does not match the manifest');
            }
            $restoredSql = $extracted . DIRECTORY_SEPARATOR . 'restored.sql';
            $written = file_put_contents($restoredSql, SchemaDump::rewrite($schemaSql, $schema, self::RESTORED));
            if ($written === false) {
                throw new RuntimeException('could not write the restored schema');
            }
            $this->tools->psql(BackupConnection::admin(), $restoredSql);
            $this->assertSameBooks($schema, self::RESTORED);
            $this->assertRefused(self::RESTORED);
        } finally {
            if ($archive !== null) {
                $this->archives->delete($archive);
            }
            $this->removeFixture($schema);
            if ($role !== null) {
                $this->dropPoster($role);
            }
        }
    }

    private function owner(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO platform.owners (id, tenant_id, username, password_hash)
             SELECT ?::uuid, id, ?, ? FROM platform.tenants WHERE slug = ?'
        );
        $statement->execute([
            '01900000-0000-7000-8000-00000000d0b1',
            'proof-owner',
            'hash',
            self::SLUG,
        ]);
    }

    private function sale(string $schema): void
    {
        $this->pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $this->pdo->beginTransaction();
        $this->pdo->exec(
            "INSERT INTO vendors (id, code, first_name, last_name)
             VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane')"
        );
        $this->pdo->exec(
            "INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
             VALUES (
                '01900000-0000-7000-8000-000000000021',
                '01900000-0000-7000-8000-000000000001',
                '2026-01-01', '2026-12-31', 'open')"
        );
        $this->pdo->exec(
            "INSERT INTO journals (
                id, book_id, posting_key, posting_date, occurred_at, period_id,
                currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
             ) VALUES (
                '01900000-0000-7000-8000-000000000501',
                '01900000-0000-7000-8000-000000000001',
                'sale:1001', '2026-03-01', '2026-03-01 12:00:00+00',
                '01900000-0000-7000-8000-000000000021',
                'USD', 'sale', '1001', 'Cash sale 1001', 10000, 10000)"
        );
        $this->pdo->exec(
            "INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
             VALUES
                ('01900000-0000-7000-8000-000000000501', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 10000, 0),
                ('01900000-0000-7000-8000-000000000501', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 2000)"
        );
        $this->pdo->exec(
            "INSERT INTO journal_lines (
                journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
             ) VALUES
                ('01900000-0000-7000-8000-000000000501', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000013', 0, 800, NULL, NULL),
                ('01900000-0000-7000-8000-000000000501', '01900000-0000-7000-8000-000000000001', 4, '01900000-0000-7000-8000-000000000014', 0, 7200, 'vendor', '01900000-0000-7000-8000-000000000101')"
        );
        $this->pdo->exec('SET CONSTRAINTS ALL IMMEDIATE');
        $this->pdo->commit();
    }

    private function assertSameBooks(string $source, string $restored): void
    {
        $sourceBooks = $this->books($source);
        $restoredBooks = $this->books($restored);
        if ($sourceBooks !== $restoredBooks) {
            throw new RuntimeException('the restored mall does not match the backup');
        }
        if ($sourceBooks !== ['journals' => '1', 'last_no' => '1', 'payable' => '7200']) {
            throw new RuntimeException('the backup does not contain the posted sale');
        }
    }

    /**
     * @return array{journals: string, last_no: string, payable: string}
     */
    private function books(string $schema): array
    {
        $this->pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $journals = $this->pdo->query('SELECT count(*) FROM journals');
        $lastNo = $this->pdo->query(
            "SELECT last_no FROM book_journal_counters WHERE book_id = '01900000-0000-7000-8000-000000000001'"
        );
        $payable = $this->pdo->query(
            "SELECT COALESCE(sum(credit_minor - debit_minor), 0)
             FROM journal_lines
             WHERE account_id = '01900000-0000-7000-8000-000000000014'"
        );
        $journalCount = $journals === false ? false : $journals->fetchColumn();
        $number = $lastNo === false ? false : $lastNo->fetchColumn();
        $balance = $payable === false ? false : $payable->fetchColumn();
        if (!is_string($journalCount) && !is_int($journalCount)) {
            throw new RuntimeException('could not count journals');
        }
        if (!is_string($number) && !is_int($number)) {
            throw new RuntimeException('could not read the journal counter');
        }
        if (!is_string($balance) && !is_int($balance)) {
            throw new RuntimeException('could not read vendor payable');
        }

        return [
            'journals' => (string) $journalCount,
            'last_no' => (string) $number,
            'payable' => (string) $balance,
        ];
    }

    private function assertRefused(string $schema): void
    {
        $this->pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        try {
            $this->pdo->exec('TRUNCATE journal_lines, journals');
            throw new RuntimeException('truncate was accepted');
        } catch (PDOException $error) {
            if ((string) $error->getCode() !== '25006') {
                throw $error;
            }
        }
        try {
            $this->pdo->exec(
                "INSERT INTO journals (
                    id, book_id, posting_key, posting_date, occurred_at, period_id,
                    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
                 ) VALUES (
                    '01900000-0000-7000-8000-000000000509',
                    '01900000-0000-7000-8000-000000000001',
                    'sale:1001', '2026-03-02', '2026-03-02 12:00:00+00',
                    '01900000-0000-7000-8000-000000000021',
                    'USD', 'sale', '1001', 'Duplicate sale', 1000, 1000)"
            );
            throw new RuntimeException('a duplicate posting key was accepted');
        } catch (PDOException $error) {
            if ((string) $error->getCode() !== '23505') {
                throw $error;
            }
        }
    }

    private function removeFixture(?string $schema): void
    {
        $this->pdo->exec('DROP SCHEMA IF EXISTS ' . self::RESTORED . ' CASCADE');
        if ($schema !== null && preg_match('/^tenant_[0-9a-f]{32}$/', $schema) === 1) {
            $this->pdo->exec('DROP SCHEMA IF EXISTS ' . $schema . ' CASCADE');
            $this->dropPoster('poster_' . substr($schema, strlen('tenant_')));
        }
        $statement = $this->pdo->prepare('SELECT id, schema_name FROM platform.tenants WHERE slug = ?');
        $statement->execute([self::SLUG]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return;
        }
        $id = $row['id'] ?? null;
        $left = $row['schema_name'] ?? null;
        if (is_string($left) && preg_match('/^tenant_[0-9a-f]{32}$/', $left) === 1) {
            $this->pdo->exec('DROP SCHEMA IF EXISTS ' . $left . ' CASCADE');
            $this->dropPoster('poster_' . substr($left, strlen('tenant_')));
        }
        if (is_string($id)) {
            $this->pdo->prepare('DELETE FROM platform.owners WHERE tenant_id = ?::uuid')->execute([$id]);
            $this->pdo->prepare('DELETE FROM platform.tenants WHERE id = ?::uuid')->execute([$id]);
        }
    }

    private function dropPoster(string $role): void
    {
        if (preg_match('/^poster_[0-9a-f]{32}$/', $role) !== 1) {
            return;
        }
        $exists = $this->pdo->prepare('SELECT 1 FROM pg_roles WHERE rolname = ?');
        $exists->execute([$role]);
        if ($exists->fetchColumn() === false) {
            return;
        }
        $member = $this->pdo->prepare(
            "SELECT 1
             FROM pg_auth_members AS membership
             JOIN pg_roles AS granted ON granted.oid = membership.roleid
             JOIN pg_roles AS grantee ON grantee.oid = membership.member
             WHERE granted.rolname = ? AND grantee.rolname = 'stall_app'"
        );
        $member->execute([$role]);
        if ($member->fetchColumn() !== false) {
            $this->pdo->exec('REVOKE ' . $role . ' FROM stall_app');
        }
        $this->pdo->exec('REVOKE USAGE ON SCHEMA platform FROM ' . $role);
        $this->pdo->exec('REVOKE EXECUTE ON FUNCTION platform.latest_owner_backup(uuid) FROM ' . $role);
        $this->pdo->exec(
            'REVOKE EXECUTE ON FUNCTION platform.record_owner_backup(uuid, uuid, bigint, text) FROM ' . $role
        );
        $this->pdo->exec('DROP ROLE ' . $role);
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    require dirname(__DIR__) . '/vendor/autoload.php';
    require_once __DIR__ . '/load-env.php';
    loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

    $host = getenv('STALL_DB_HOST');
    $port = getenv('STALL_DB_PORT');
    $name = getenv('STALL_DB_NAME');
    $user = getenv('STALL_DB_USER');
    $password = getenv('STALL_DB_PASSWORD');
    if (!is_string($host) || $host === ''
        || !is_string($port) || $port === ''
        || !is_string($name) || $name === ''
        || !is_string($user) || $user === ''
        || !is_string($password) || $password === ''
    ) {
        fwrite(STDERR, "STALL_DB_HOST, STALL_DB_PORT, STALL_DB_NAME, STALL_DB_USER, and STALL_DB_PASSWORD are required\n");
        exit(1);
    }

    $logDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log';
    if (!is_dir($logDirectory) && !mkdir($logDirectory, 0755, true) && !is_dir($logDirectory)) {
        fwrite(STDERR, "The log directory could not be created.\n");
        exit(1);
    }
    $pdo = new PDO(
        'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $logger = new FileLogger($logDirectory . DIRECTORY_SEPARATOR . 'app.log');
    $tools = new PostgresTools(new CommandProcess($logger));
    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql';
    try {
        (new BackupProof(
            $pdo,
            new TenantProvisioner($pdo, $directory),
            new TenantBackup($logger, $tools, new BackupWorkspace($logger, new DirectoryTree()), new PlatformSnapshot()),
            $tools
        ))->run();
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
