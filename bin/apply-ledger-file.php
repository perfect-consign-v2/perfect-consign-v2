<?php

declare(strict_types=1);

require __DIR__ . '/load-env.php';
loadStallEnv(dirname(__DIR__) . '/.env');
require __DIR__ . '/provision-tenant.php';

$file = $argv[1] ?? '';
if ($file === '' || !str_ends_with($file, '.sql')) {
    fwrite(STDERR, "usage: php bin/apply-ledger-file.php <sql file name>\n");
    exit(1);
}

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
$provisioner = new TenantProvisioner($pdo, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql');
$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . $file;
$sql = file_get_contents($path);
if ($sql === false) {
    fwrite(STDERR, "could not read $file\n");
    exit(1);
}

$rows = $pdo->query('SELECT slug, schema_name FROM platform.tenants');
if ($rows === false) {
    fwrite(STDERR, "could not list tenants\n");
    exit(1);
}
foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $schema = $row['schema_name'];
    $slug = $row['slug'];
    if (!is_string($schema) || !is_string($slug)) {
        continue;
    }
    $role = $provisioner->roleName($slug, $schema);
    $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
    $pdo->exec($provisioner->rewrite($sql, $schema, $role));
    fwrite(STDOUT, $slug . ' ' . $schema . PHP_EOL);
}
