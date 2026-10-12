<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath((string) $argv[0]) !== realpath(__FILE__)) {
    return;
}

require_once __DIR__ . '/load-env.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

foreach (
    [
        'STALL_DB_HOST' => '127.0.0.1',
        'STALL_DB_PORT' => '5436',
        'STALL_DB_NAME' => 'consign',
        'STALL_DB_USER' => 'stall',
        'STALL_DB_PASSWORD' => 'stall',
    ] as $key => $default
) {
    $value = getenv($key);
    if (!is_string($value) || $value === '') {
        putenv($key . '=' . $default);
    }
}

try {
    $schema = (new PerfectConsign\Feature\Dev\DevTenantReset())->load();
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, $schema . "\n");
