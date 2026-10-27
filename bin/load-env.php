<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\EnvFile;

function loadStallEnv(string $path): void
{
    if (!class_exists(EnvFile::class)) {
        $autoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (is_file($autoload)) {
            require $autoload;
        }
    }
    EnvFile::apply($path);
}
