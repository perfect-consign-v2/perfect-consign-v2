<?php

declare(strict_types=1);

use PerfectConsign\Tests\Codeception\ProbeHarness;

$root = dirname(__DIR__, 2);
require_once $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require_once $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'load-env.php';

loadStallEnv($root . DIRECTORY_SEPARATOR . '.env');

$configured = getenv('WEB_BASE_URL');
if (is_string($configured) && $configured !== '') {
    return [
        'WEB_BASE_URL' => rtrim($configured, '/'),
    ];
}

$harness = ProbeHarness::boot($root);

return [
    'WEB_BASE_URL' => $harness->baseUrl(),
];
