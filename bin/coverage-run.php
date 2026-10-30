<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$directory = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'coverage';
if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    fwrite(STDERR, "coverage directory could not be created\n");
    exit(1);
}
clearCoverageDumps($directory);

$env = getenv();
if (!is_array($env)) {
    fwrite(STDERR, "the process environment could not be read\n");
    exit(1);
}
$env['XDEBUG_MODE'] = 'coverage';
$env['PC_COVERAGE'] = $directory;
$env['APP_ENV'] = 'coverage';

$phpunit = PHP_BINARY;
$phpunitBin = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit';
$extra = [];
foreach (array_slice($argv, 1) as $argument) {
    if (is_string($argument)) {
        $extra[] = $argument;
    }
}

$tests = 0;
if (argvHasTestsuite($extra)) {
    $tests = runPhpunit($phpunit, $phpunitBin, $extra, $root, $env);
} else {
    $tests = runPhpunit(
        $phpunit,
        $phpunitBin,
        array_merge(['--testsuite', 'unit,integration'], $extra),
        $root,
        $env,
    );
    if ($tests === 0) {
        $tests = runPhpunit(
            $phpunit,
            $phpunitBin,
            array_merge(['--testsuite', 'http'], $extra),
            $root,
            $env,
        );
    }
}

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

/**
 * @param list<string> $arguments
 * @param array<string, string> $env
 */
function runPhpunit(string $php, string $phpunitBin, array $arguments, string $root, array $env): int
{
    $command = array_merge([$php, $phpunitBin], $arguments);
    $pipes = [];
    $process = proc_open(
        $command,
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

    return proc_close($process);
}

/**
 * @param list<string> $arguments
 */
function argvHasTestsuite(array $arguments): bool
{
    foreach ($arguments as $argument) {
        if ($argument === '--testsuite' || str_starts_with($argument, '--testsuite=')) {
            return true;
        }
    }

    return false;
}

function clearCoverageDumps(string $directory): void
{
    $existingPhp = glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [];
    $existingCov = glob($directory . DIRECTORY_SEPARATOR . '*.cov') ?: [];
    foreach (array_merge($existingPhp, $existingCov) as $old) {
        if (is_file($old) && !unlink($old)) {
            fwrite(STDERR, "coverage dump could not be cleared\n");
            exit(1);
        }
    }
}
