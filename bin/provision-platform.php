<?php

declare(strict_types=1);

require_once __DIR__ . '/load-env.php';

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

$installed = false;
try {
    $existing = $pdo->query(
        "SELECT 1 FROM pg_event_trigger WHERE evtname = 'platform_reject_guard_function_replace'"
    );
    $installed = $existing !== false && $existing->fetchColumn() !== false;
} catch (PDOException) {
    $installed = false;
}
if ($installed) {
    fwrite(STDOUT, "platform already installed\n");
    exit(0);
}

$directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql';
foreach (['000_platform.sql', '015_stall_app.sql', '016_owners.sql', '018_owner_backup.sql'] as $file) {
    $sql = file_get_contents($directory . DIRECTORY_SEPARATOR . $file);
    if ($sql === false) {
        fwrite(STDERR, "could not read $file\n");
        exit(1);
    }
    $pdo->exec($sql);
}

fwrite(STDOUT, "platform installed\n");
