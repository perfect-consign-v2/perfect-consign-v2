<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

require_once __DIR__ . '/load-env.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

use PerfectConsign\Feature\Dev\DemoLoginEnsure;

try {
    $notes = (new DemoLoginEnsure())->ensure();
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
foreach ($notes as $note) {
    fwrite(STDOUT, $note . "\n");
}
