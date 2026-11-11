<?php

declare(strict_types=1);

/**
 * Drop schema_migrations rows for removed forward files, and refresh checksums
 * for files that were edited in place during a squash.
 *
 * Usage:
 *   php bin/forget-tenant-migrations.php forget <file.sql> [<file.sql> ...]
 *   php bin/forget-tenant-migrations.php refresh <file.sql> [<file.sql> ...]
 */

require_once __DIR__ . '/load-env.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use PerfectConsign\Schema\SchemaCatalog;

loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

$action = $argv[1] ?? '';
$files = array_values(array_filter(array_slice($argv, 2), static fn (mixed $f): bool => is_string($f) && $f !== ''));
if (!in_array($action, ['forget', 'refresh'], true) || $files === []) {
    fwrite(STDERR, "usage: php bin/forget-tenant-migrations.php forget|refresh <file.sql> [...]\n");
    exit(1);
}

foreach ($files as $file) {
    if (preg_match('/^[0-9]{3}_[a-z0-9_]+\.sql$/', $file) !== 1) {
        fwrite(STDERR, "invalid migration file name: {$file}\n");
        exit(1);
    }
}

$host = getenv('STALL_DB_HOST') ?: '127.0.0.1';
$port = getenv('STALL_DB_PORT') ?: '5436';
$name = getenv('STALL_DB_NAME') ?: 'consign';
$user = getenv('STALL_DB_USER') ?: 'stall';
$password = getenv('STALL_DB_PASSWORD') ?: 'stall';

$pdo = new PDO(
    'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$catalog = new SchemaCatalog(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql');
$tenants = $pdo->query('SELECT slug, schema_name FROM platform.tenants ORDER BY slug');
if ($tenants === false) {
    fwrite(STDERR, "could not list tenants\n");
    exit(1);
}

while (($row = $tenants->fetch(PDO::FETCH_ASSOC)) !== false) {
    $slug = $row['slug'] ?? null;
    $schema = $row['schema_name'] ?? null;
    if (!is_string($slug) || !is_string($schema) || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
        continue;
    }
    $table = $schema . '.schema_migrations';
    $exists = $pdo->query(
        "SELECT to_regclass(" . $pdo->quote($table) . ")"
    )->fetchColumn();
    if ($exists === null || $exists === false) {
        echo $slug . " no schema_migrations\n";
        continue;
    }
    foreach ($files as $file) {
        if ($action === 'forget') {
            $stmt = $pdo->prepare('DELETE FROM ' . $table . ' WHERE filename = ?');
            $stmt->execute([$file]);
            echo $slug . ' forgot ' . $file . ' rows=' . $stmt->rowCount() . "\n";
            continue;
        }
        $checksum = $catalog->checksum($file);
        $stmt = $pdo->prepare('UPDATE ' . $table . ' SET checksum = ? WHERE filename = ?');
        $stmt->execute([$checksum, $file]);
        echo $slug . ' refreshed ' . $file . ' rows=' . $stmt->rowCount() . "\n";
    }
}
