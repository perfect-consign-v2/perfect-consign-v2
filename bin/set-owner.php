<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

$slug = $argv[1] ?? '';
$username = $argv[2] ?? '';
$password = getenv('STALL_OWNER_PASSWORD');
$host = getenv('STALL_DB_HOST');
$port = getenv('STALL_DB_PORT');
$name = getenv('STALL_DB_NAME');
$user = getenv('STALL_DB_USER');
$adminPassword = getenv('STALL_DB_PASSWORD');
if (
    !is_string($slug) || $slug === ''
    || !is_string($username) || $username === ''
    || !is_string($password) || $password === ''
    || !is_string($host) || $host === ''
    || !is_string($port) || $port === ''
    || !is_string($name) || $name === ''
    || !is_string($user) || $user === ''
    || !is_string($adminPassword) || $adminPassword === ''
) {
    fwrite(STDERR, "usage: php bin/set-owner.php <slug> <username>\nSTALL_OWNER_PASSWORD, STALL_DB_HOST, STALL_DB_PORT, STALL_DB_NAME, STALL_DB_USER, and STALL_DB_PASSWORD are required\n");
    exit(1);
}

$pdo = new PDO(
    'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
    $user,
    $adminPassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$hash = password_hash($password, PASSWORD_DEFAULT);
if ($hash === false) {
    fwrite(STDERR, "the password hash could not be created\n");
    exit(1);
}
$statement = $pdo->prepare(
    'INSERT INTO platform.owners (id, tenant_id, username, password_hash)
     SELECT uuidv7(), id, ?, ?
     FROM platform.tenants
     WHERE slug = ?
     ON CONFLICT (tenant_id) DO UPDATE
        SET username = EXCLUDED.username,
            password_hash = EXCLUDED.password_hash'
);
$statement->execute([$username, $hash, $slug]);
if ($statement->rowCount() < 1) {
    fwrite(STDERR, "that mall was not found\n");
    exit(1);
}
fwrite(STDOUT, "owner saved\n");
