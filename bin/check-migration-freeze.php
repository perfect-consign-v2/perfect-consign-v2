<?php

declare(strict_types=1);

/**
 * @param list<string> $changed
 * @return list<string>
 */
function migrationFreezeFailures(int $highest, array $changed): array
{
    $failures = [];
    foreach ($changed as $file) {
        if (preg_match('#(?:^|/)sql/(\d{3})_[a-z0-9_]+\.sql$#', $file, $match) !== 1) {
            continue;
        }
        $number = (int) $match[1];
        if ($number <= $highest) {
            $failures[] = $file . ' is at or below ' . sprintf('%03d', $highest);
        }
    }

    return $failures;
}

function migrationFreezeHighest(string $root): int
{
    $base = trim(migrationFreezeGit($root, ['merge-base', 'HEAD', 'origin/master']));
    if ($base === '') {
        throw new RuntimeException('migration freeze base is missing');
    }
    $listed = migrationFreezeGit($root, ['ls-tree', '-r', '--name-only', $base, 'sql']);
    $highest = 0;
    foreach (preg_split("/\r\n|\n|\r/", $listed) ?: [] as $file) {
        if (preg_match('#^sql/(\d{3})_[a-z0-9_]+\.sql$#', $file, $match) === 1) {
            $highest = max($highest, (int) $match[1]);
        }
    }

    return $highest;
}

/**
 * @return list<string>
 */
function migrationFreezeChanged(string $root, int $highest): array
{
    $base = trim(migrationFreezeGit($root, ['merge-base', 'HEAD', 'origin/master']));
    $diff = migrationFreezeGit($root, ['diff', '--name-only', $base, 'HEAD', '--', 'sql']);
    $changed = [];
    foreach (preg_split("/\r\n|\n|\r/", $diff) ?: [] as $file) {
        if ($file !== '') {
            $changed[] = str_replace('\\', '/', $file);
        }
    }

    return migrationFreezeFailures($highest, $changed);
}

/**
 * @param list<string> $arguments
 */
function migrationFreezeGit(string $root, array $arguments): string
{
    $command = array_merge(['git', '-C', $root], $arguments);
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $spec, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('git could not start');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0 || !is_string($stdout)) {
        throw new RuntimeException('git failed');
    }

    return $stdout;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === realpath(__FILE__)) {
    try {
        $root = dirname(__DIR__);
        $failures = migrationFreezeChanged($root, migrationFreezeHighest($root));
    } catch (RuntimeException $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
    if ($failures !== []) {
        fwrite(STDERR, implode("\n", $failures) . "\n");
        exit(1);
    }
    fwrite(STDOUT, "ok migration freeze\n");
}
