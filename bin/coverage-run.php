<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$directory = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'coverage';
if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    fwrite(STDERR, "coverage directory could not be created\n");
    exit(1);
}
$env = getenv();
if (!is_array($env)) {
    fwrite(STDERR, "the process environment could not be read\n");
    exit(1);
}
$env['XDEBUG_MODE'] = 'coverage';
$env['PC_COVERAGE'] = $directory;
$env['APP_ENV'] = 'coverage';
$phpunit = [PHP_BINARY, $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit'];
$extra = array_slice($argv, 1);
if ($extra === []) {
    $extra = ['--testsuite', 'unit,integration,http'];
}
foreach ($extra as $argument) {
    if (is_string($argument)) {
        $phpunit[] = $argument;
    }
}
$pipes = [];
$process = proc_open(
    $phpunit,
    [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR],
    $pipes,
    $root,
    $env,
    ['bypass_shell' => true]
);
if (!is_resource($process)) {
    fwrite(STDERR, "phpunit did not start\n");
    exit(1);
}
if (isset($pipes[0]) && is_resource($pipes[0])) {
    fclose($pipes[0]);
}
$tests = proc_close($process);
$reportPipes = [];
$report = proc_open(
    [PHP_BINARY, $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'coverage-report.php'],
    [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR],
    $reportPipes,
    $root,
    $env,
    ['bypass_shell' => true]
);
if (!is_resource($report)) {
    fwrite(STDERR, "the coverage report did not start\n");
    exit(1);
}
if (isset($reportPipes[0]) && is_resource($reportPipes[0])) {
    fclose($reportPipes[0]);
}
$coverage = proc_close($report);
if ($tests !== 0) {
    exit($tests);
}
exit($coverage);
