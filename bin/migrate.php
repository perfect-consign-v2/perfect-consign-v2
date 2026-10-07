<?php

declare(strict_types=1);

require_once __DIR__ . '/load-env.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use PerfectConsign\Schema\SchemaCatalog;
use PerfectConsign\Schema\SchemaMigrator;

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

$migrator = new SchemaMigrator(
    $pdo,
    new SchemaCatalog(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql')
);

$command = $argv[1] ?? '';
try {
    if ($command === 'platform') {
        $migrator->migratePlatform();
        fwrite(STDOUT, "platform current\n");
        exit(0);
    }
    if ($command === 'tenant') {
        $slug = $argv[2] ?? '';
        if (!is_string($slug) || $slug === '') {
            usage();
        }
        $migrator->migrateTenant($slug);
        fwrite(STDOUT, $slug . " current\n");
        exit(0);
    }
    if ($command === 'tenant-all') {
        $listed = $pdo->query('SELECT slug FROM platform.tenants ORDER BY slug');
        if ($listed === false) {
            throw new RuntimeException('could not list tenants');
        }
        while (($slug = $listed->fetchColumn()) !== false) {
            if (!is_string($slug)) {
                throw new RuntimeException('could not list tenants');
            }
            $migrator->migrateTenant($slug);
            fwrite(STDOUT, $slug . " current\n");
        }
        exit(0);
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

usage();

function usage(): never
{
    fwrite(STDERR, "usage: php bin/migrate.php platform|tenant <slug>|tenant-all\n");
    fwrite(STDERR, "Migrations are forward only. There is no down path.\n");
    exit(1);
}
