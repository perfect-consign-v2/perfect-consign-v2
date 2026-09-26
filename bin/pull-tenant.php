<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/load-env.php';
require_once __DIR__ . '/provision-tenant.php';

loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

use PerfectApp\Logger\FileLogger;
use PerfectConsign\Feature\Backup\BackupConnection;
use PerfectConsign\Feature\Backup\BackupException;
use PerfectConsign\Feature\Backup\CommandProcess;
use PerfectConsign\Feature\Backup\PostgresTools;
use PerfectConsign\Feature\Backup\SchemaDump;
use PerfectConsign\Feature\Backup\TenantBackup;

$slug = $argv[1] ?? '';
if (!is_string($slug) || preg_match('/^[a-z][a-z0-9-]{0,62}$/', $slug) !== 1) {
    fwrite(STDERR, "usage: php bin/pull-tenant.php <slug>\n");
    exit(1);
}

try {
    $destination = BackupConnection::admin();
    if (!$destination->isLocalRestoreTarget()) {
        throw new BackupException('a tenant pull only restores onto local port 5436 database consign');
    }
    $source = BackupConnection::sourceBackup();
} catch (BackupException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

$logDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log';
if (!is_dir($logDirectory) && !mkdir($logDirectory, 0755, true) && !is_dir($logDirectory)) {
    fwrite(STDERR, "The log directory could not be created.\n");
    exit(1);
}
$logger = new FileLogger($logDirectory . DIRECTORY_SEPARATOR . 'app.log');
$tools = new PostgresTools(new CommandProcess($logger));
$archives = new TenantBackup($logger, $tools);
$archive = null;
try {
    $archive = $archives->create($source, $slug);
    $extracted = $archive->directory . DIRECTORY_SEPARATOR . 'out';
    if (!mkdir($extracted, 0700) && !is_dir($extracted)) {
        throw new BackupException('The backup could not be read.');
    }
    $zip = new ZipArchive();
    if ($zip->open($archive->path) !== true || !$zip->extractTo($extracted) || !$zip->close()) {
        throw new BackupException('The backup could not be read.');
    }
    $manifest = manifestFields($extracted . DIRECTORY_SEPARATOR . 'manifest.txt');
    if (($manifest['slug'] ?? '') !== $slug) {
        throw new BackupException('the backup is for a different mall');
    }
    $schema = $manifest['schema'] ?? '';
    if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
        throw new BackupException('schema name is not a tenant schema');
    }
    $pdo = $destination->pdo();
    $existing = $pdo->prepare('SELECT id, schema_name FROM platform.tenants WHERE slug = ?');
    $existing->execute([$slug]);
    $local = $existing->fetch(PDO::FETCH_ASSOC);
    $schemaFile = $extracted . DIRECTORY_SEPARATOR . 'schema.sql';
    if (is_array($local) && is_string($local['schema_name'] ?? null) && is_string($local['id'] ?? null)) {
        $localSchema = $local['schema_name'];
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $localSchema) !== 1) {
            throw new BackupException('schema name is not a tenant schema');
        }
        $sql = file_get_contents($schemaFile);
        if ($sql === false) {
            throw new BackupException('The backup could not be read.');
        }
        $rewritten = $extracted . DIRECTORY_SEPARATOR . 'schema-local.sql';
        $written = file_put_contents($rewritten, SchemaDump::rewrite($sql, $schema, $localSchema));
        if ($written === false) {
            throw new BackupException('The backup could not be written.');
        }
        $pdo->exec('DROP SCHEMA IF EXISTS ' . $localSchema . ' CASCADE');
        $tools->psql($destination, $rewritten);
        $pdo->prepare('DELETE FROM platform.owners WHERE tenant_id = ?::uuid')->execute([$local['id']]);
        if (isset($manifest['owner_id'], $manifest['owner_username'], $manifest['owner_password_hash'])) {
            $insertOwner = $pdo->prepare(
                'INSERT INTO platform.owners (id, tenant_id, username, password_hash) VALUES (?::uuid, ?::uuid, ?, ?)'
            );
            $insertOwner->execute([
                $manifest['owner_id'],
                $local['id'],
                $manifest['owner_username'],
                $manifest['owner_password_hash'],
            ]);
        }
        $schema = $localSchema;
    } else {
        $conflict = $pdo->prepare('SELECT slug FROM platform.tenants WHERE schema_name = ?');
        $conflict->execute([$schema]);
        if ($conflict->fetchColumn() !== false) {
            throw new BackupException('that schema already belongs to another local mall');
        }
        $tools->psql($destination, $schemaFile);
        $tools->psql($destination, $extracted . DIRECTORY_SEPARATOR . 'platform.sql');
    }
    $provisioner = new TenantProvisioner($pdo, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql');
    $role = $provisioner->roleName($slug, $schema);
    $pdo->exec(sprintf(
        "DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = %s) THEN CREATE ROLE %s NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE; END IF; END \$\$",
        $pdo->quote($role),
        $role
    ));
    $grants = file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . '017_poster_grants.sql');
    if ($grants === false) {
        throw new BackupException('could not read 017_poster_grants.sql');
    }
    $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog');
    $pdo->exec($provisioner->rewrite($grants, $schema, $role));
    $provisioner->grantApp($role);
    $provisioner->grantBackup($schema, $role);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} finally {
    if ($archive !== null) {
        $archives->delete($archive);
    }
}

fwrite(STDOUT, $slug . "\n");

/**
 * @return array<string, string>
 */
function manifestFields(string $path): array
{
    $text = file_get_contents($path);
    if ($text === false) {
        throw new BackupException('The backup could not be read.');
    }
    $fields = [];
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        if ($line === '' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $fields[$key] = $value;
    }

    return $fields;
}
