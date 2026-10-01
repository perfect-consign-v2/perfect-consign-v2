<?php

declare(strict_types=1);

require_once __DIR__ . '/load-env.php';
require_once __DIR__ . '/provision-tenant.php';
require_once __DIR__ . '/wipe-tenant.php';

loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

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

$slug = 'dev';
wipeTenant($pdo, $slug, 'dev');
$provisioner = new TenantProvisioner($pdo, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql');
$schema = $provisioner->provision($slug, 'Dev');
$role = $provisioner->roleName($slug, $schema);
$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'dev_tenant.sql';
$sql = file_get_contents($path);
if ($sql === false) {
    fwrite(STDERR, "could not read dev_tenant.sql\n");
    exit(1);
}
$pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
$pdo->exec($provisioner->rewrite($sql, $schema, $role));
fwrite(STDOUT, $schema . "\n");
