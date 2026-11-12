<?php

declare(strict_types=1);

/**
 * Drop and recreate STALL_DB_NAME so the two-installer baseline can provision.
 * Refused unless STALL_ALLOW_PLATFORM_REINSTALL=true. That flag is the only gate:
 * disposable demos often use APP_ENV=production for the route manifest cache.
 *
 *   php bin/reinstall-empty-database.php
 *   php bin/provision-platform.php
 *   php bin/seed-mall.php
 */

require_once __DIR__ . '/load-env.php';

loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

$allow = getenv('STALL_ALLOW_PLATFORM_REINSTALL');
if (!is_string($allow) || !in_array(strtolower($allow), ['1', 'true', 'yes'], true)) {
    fwrite(STDERR, "STALL_ALLOW_PLATFORM_REINSTALL=true is required\n");
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
if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
    fwrite(STDERR, "STALL_DB_NAME is not a safe database name\n");
    exit(1);
}

$pdo = new PDO(
    'pgsql:host=' . $host . ';port=' . $port . ';dbname=postgres',
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec(
    "SELECT pg_terminate_backend(pid)
     FROM pg_stat_activity
     WHERE datname = " . $pdo->quote($name) . "
       AND pid <> pg_backend_pid()"
);
$pdo->exec('DROP DATABASE IF EXISTS ' . $name);
$pdo->exec('CREATE DATABASE ' . $name . ' OWNER ' . $user);

fwrite(STDOUT, "database {$name} recreated; run provision-platform then seed-mall\n");
