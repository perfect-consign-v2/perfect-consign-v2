<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/load-env.php';

loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

use PerfectConsign\Feature\Pc1Mirror\MirrorCli;
use PerfectConsign\Feature\Pc1Mirror\MirrorException;

$root = dirname(__DIR__);
$artifactRoot = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'pc1-extracts';

try {
    $code = (new MirrorCli(
        $root,
        $artifactRoot,
        $root . DIRECTORY_SEPARATOR . 'sql',
    ))->run($argv);
    exit($code);
} catch (MirrorException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
