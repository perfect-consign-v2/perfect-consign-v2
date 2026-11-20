<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath((string) $argv[0]) !== realpath(__FILE__)) {
    return;
}

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/load-env.php';

use PerfectConsign\Feature\Admin\Mfa\AdminMfaKeyFile;

$path = $argv[1] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
if (!is_string($path) || $path === '') {
    fwrite(STDERR, "the environment file is missing\n");
    exit(1);
}

try {
    $existing = AdminMfaKeyFile::stored($path);
    if ($existing !== null) {
        AdminMfaKeyFile::present($path, 0);
        exit(0);
    }
    loadStallEnv($path);
    AdminMfaKeyFile::present($path, enrolledAdminCount());
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

function enrolledAdminCount(): int
{
    $pdo = adminMfaPdo();
    try {
        $count = $pdo->query('SELECT count(*) FROM platform.admins WHERE mfa_secret IS NOT NULL');
    } catch (PDOException $error) {
        $state = $error->getCode();
        if ($state === '42703' || $state === '42P01') {
            return 0;
        }
        throw new RuntimeException('the admin authenticator key could not be checked', 0, $error);
    }
    if ($count === false) {
        throw new RuntimeException('the admin authenticator key could not be checked');
    }
    $enrolled = $count->fetchColumn();
    if (is_int($enrolled)) {
        return $enrolled;
    }
    if (!is_string($enrolled) || ctype_digit($enrolled) !== true) {
        throw new RuntimeException('the admin authenticator key could not be checked');
    }

    return (int) $enrolled;
}

function adminMfaPdo(): PDO
{
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
        throw new RuntimeException('STALL_DB_PASSWORD is required');
    }
    if (str_contains($host, ';') || str_contains($name, ';')) {
        throw new RuntimeException('STALL_DB_HOST or STALL_DB_NAME is invalid');
    }
    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('STALL_DB_PORT is invalid');
    }

    return new PDO(
        'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}
