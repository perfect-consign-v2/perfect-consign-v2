<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

$password = getenv('STALL_APP_PASSWORD');
$backupPassword = getenv('STALL_BACKUP_PASSWORD');
$host = getenv('STALL_DB_HOST');
$port = getenv('STALL_DB_PORT');
$name = getenv('STALL_DB_NAME');
$user = getenv('STALL_DB_USER');
$adminPassword = getenv('STALL_DB_PASSWORD');
if (
    !is_string($password) || $password === ''
    || !is_string($backupPassword) || $backupPassword === ''
    || !is_string($host) || $host === ''
    || !is_string($port) || $port === ''
    || !is_string($name) || $name === ''
    || !is_string($user) || $user === ''
    || !is_string($adminPassword) || $adminPassword === ''
) {
    fwrite(STDERR, "STALL_APP_PASSWORD, STALL_BACKUP_PASSWORD, STALL_DB_HOST, STALL_DB_PORT, STALL_DB_NAME, STALL_DB_USER, and STALL_DB_PASSWORD are required\n");
    exit(1);
}

$pdo = new PDO(
    'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
    $user,
    $adminPassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$backupRole = $pdo->query("SELECT 1 FROM pg_roles WHERE rolname = 'stall_backup'");
if ($backupRole === false || $backupRole->fetchColumn() === false) {
    fwrite(STDERR, "stall_backup is missing. Apply sql/000_platform.sql first.\n");
    exit(1);
}
$pdo->exec('ALTER ROLE stall_app LOGIN PASSWORD ' . $pdo->quote($password));
$pdo->exec('ALTER ROLE stall_backup LOGIN PASSWORD ' . $pdo->quote($backupPassword));
fwrite(STDOUT, "stall_app can log in\n");
fwrite(STDOUT, "stall_backup can log in\n");
