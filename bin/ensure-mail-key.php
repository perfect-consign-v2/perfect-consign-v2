<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PerfectConsign\Shared\Mail\MailKeyFile;

$path = $argv[1] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
if (!is_string($path) || $path === '') {
    fwrite(STDERR, "the environment file is missing\n");
    exit(1);
}
try {
    MailKeyFile::present($path);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
