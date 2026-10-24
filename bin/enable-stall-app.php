<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

require_once __DIR__ . '/load-env.php';
loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

$password = getenv('STALL_APP_PASSWORD');
$backupPassword = getenv('STALL_BACKUP_PASSWORD');
$workerPassword = getenv('STALL_WORKER_PASSWORD');
$host = getenv('STALL_DB_HOST');
$port = getenv('STALL_DB_PORT');
$name = getenv('STALL_DB_NAME');
$user = getenv('STALL_DB_USER');
$adminPassword = getenv('STALL_DB_PASSWORD');
if (
    !is_string($password) || $password === ''
    || !is_string($backupPassword) || $backupPassword === ''
    || !is_string($workerPassword) || $workerPassword === ''
) {
    fwrite(STDERR, "STALL_APP_PASSWORD, STALL_BACKUP_PASSWORD, and STALL_WORKER_PASSWORD are required\n");
    exit(1);
}
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
if (!is_string($adminPassword) || $adminPassword === '') {
    $adminPassword = 'stall';
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
$workerRole = $pdo->query("SELECT 1 FROM pg_roles WHERE rolname = 'stall_worker'");
if ($workerRole === false || $workerRole->fetchColumn() === false) {
    fwrite(STDERR, "stall_worker is missing. Apply sql/013_worker_role.sql first.\n");
    exit(1);
}
$pdo->exec('ALTER ROLE stall_app LOGIN PASSWORD ' . $pdo->quote($password));
$pdo->exec('ALTER ROLE stall_backup LOGIN PASSWORD ' . $pdo->quote($backupPassword));
$pdo->exec('ALTER ROLE stall_worker LOGIN PASSWORD ' . $pdo->quote($workerPassword));
fwrite(STDOUT, "stall_app can log in\n");
fwrite(STDOUT, "stall_backup can log in\n");
fwrite(STDOUT, "stall_worker can log in\n");
