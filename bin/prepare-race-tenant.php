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

$slug = 'race';
wipeTenant($pdo, $slug, 'race');
$provisioner = new TenantProvisioner($pdo, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql');
$schema = $provisioner->provision($slug, 'Race');
fwrite(STDOUT, $schema . "\n");
