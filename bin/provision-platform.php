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

require_once dirname(__DIR__) . '/vendor/autoload.php';

$poster = $pdo->query("SELECT 1 FROM pg_roles WHERE rolname = 'stall_poster'");
if ($poster === false) {
    fwrite(STDERR, "could not read roles\n");
    exit(1);
}
if ($poster->fetchColumn() === false) {
    $pdo->exec('CREATE ROLE stall_poster NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE');
}

try {
    (new \PerfectConsign\Schema\SchemaMigrator(
        $pdo,
        new \PerfectConsign\Schema\SchemaCatalog(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql')
    ))->migratePlatform();
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "platform current\n");
