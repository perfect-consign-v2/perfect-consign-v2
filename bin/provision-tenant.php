<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use PerfectConsign\Schema\TenantProvisioner;

if (!class_exists('TenantProvisioner', false)) {
    class_alias(TenantProvisioner::class, 'TenantProvisioner');
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    require_once __DIR__ . '/load-env.php';
    loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');
    $slug = $argv[1] ?? '';
    $displayName = $argv[2] ?? '';
    if (!is_string($slug) || $slug === '' || !is_string($displayName) || $displayName === '') {
        fwrite(STDERR, "usage: php bin/provision-tenant.php <slug> <display name>\n");
        exit(1);
    }

    $host = getenv('STALL_DB_HOST');
    $port = getenv('STALL_DB_PORT');
    $name = getenv('STALL_DB_NAME');
    $user = getenv('STALL_DB_USER');
    $password = getenv('STALL_DB_PASSWORD');
    if (
        !is_string($host) || $host === ''
        || !is_string($port) || $port === ''
        || !is_string($name) || $name === ''
        || !is_string($user) || $user === ''
        || !is_string($password) || $password === ''
    ) {
        fwrite(STDERR, "STALL_DB_HOST, STALL_DB_PORT, STALL_DB_NAME, STALL_DB_USER, and STALL_DB_PASSWORD are required\n");
        exit(1);
    }

    $pdo = new PDO(
        'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    try {
        $schema = (new TenantProvisioner($pdo, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql'))->provision($slug, $displayName);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
    fwrite(STDOUT, $schema . "\n");
}
