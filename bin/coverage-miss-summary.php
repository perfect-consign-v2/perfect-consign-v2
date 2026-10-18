<?php

declare(strict_types=1);

$lines = file(dirname(__DIR__) . '/var/coverage-miss.txt', FILE_IGNORE_NEW_LINES);
if ($lines === false || $lines === []) {
    fwrite(STDERR, "no miss dump\n");
    exit(1);
}
echo $lines[0] . PHP_EOL;
$total = 0;
for ($i = 1, $n = count($lines); $i < $n; $i += 2) {
    echo $lines[$i] . PHP_EOL;
    if (preg_match('/ (\d+)$/', $lines[$i], $match) === 1) {
        $total += (int) $match[1];
    }
}
echo 'uncovered ' . $total . PHP_EOL;
