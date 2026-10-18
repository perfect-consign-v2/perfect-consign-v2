<?php

declare(strict_types=1);

$needle = $argv[1] ?? '';
$dump = file(dirname(__DIR__) . '/var/coverage-miss.txt', FILE_IGNORE_NEW_LINES);
if ($dump === false) {
    exit(1);
}
for ($i = 1, $n = count($dump); $i < $n; $i += 2) {
    $header = $dump[$i];
    if ($needle !== '' && !str_contains($header, $needle)) {
        continue;
    }
    echo $header . PHP_EOL;
    $numbers = array_map('intval', explode(',', $dump[$i + 1] ?? ''));
    $path = dirname(__DIR__) . '/src/' . preg_replace('/ \d+$/', '', $header);
    $source = file($path);
    if ($source === false) {
        continue;
    }
    foreach ($numbers as $line) {
        if ($line < 1) {
            continue;
        }
        echo str_pad((string) $line, 4, ' ', STR_PAD_LEFT) . ' ' . rtrim($source[$line - 1]) . PHP_EOL;
    }
    echo PHP_EOL;
}
