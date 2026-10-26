<?php

declare(strict_types=1);

/**
 * Scores mutants in eight worktrees, then merges one result.
 */

$root = dirname(__DIR__);
$php = PHP_BINARY;
$shards = 8;
$out = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'mutation';
$trees = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'mutation-worktrees';
if (!is_dir($out) && !mkdir($out, 0755, true) && !is_dir($out)) {
    fwrite(STDERR, "mutation directory could not be created\n");
    exit(1);
}
if (!is_dir($trees) && !mkdir($trees, 0755, true) && !is_dir($trees)) {
    fwrite(STDERR, "worktree directory could not be created\n");
    exit(1);
}

$listEnv = processEnv();
$listEnv['PC_MUTATE_LIST'] = '1';
if (run([$php, $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mutate.php'], $root, $listEnv) !== 0) {
    exit(1);
}

$procs = [];
for ($index = 0; $index < $shards; $index++) {
    $dir = $trees . DIRECTORY_SEPARATOR . 's' . $index;
    if (!clearShardDir($dir, $root)) {
        fwrite(STDERR, "shard {$index} directory could not be cleared\n");
        exit(1);
    }
    if (run(['git', 'worktree', 'add', '--detach', $dir, 'HEAD'], $root, processEnv()) !== 0) {
        exit(1);
    }
    $vendor = $dir . DIRECTORY_SEPARATOR . 'vendor';
    if (!is_dir($vendor) && !linkVendor($root . DIRECTORY_SEPARATOR . 'vendor', $vendor)) {
        fwrite(STDERR, "vendor link could not be created\n");
        exit(1);
    }
    $shardOut = $dir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'mutation';
    if (!is_dir($shardOut) && !mkdir($shardOut, 0755, true) && !is_dir($shardOut)) {
        fwrite(STDERR, "shard directory could not be created\n");
        exit(1);
    }
    if (!copy($out . DIRECTORY_SEPARATOR . 'mutants.json', $shardOut . DIRECTORY_SEPARATOR . 'mutants.json')) {
        fwrite(STDERR, "mutant list could not be copied\n");
        exit(1);
    }
    $env = processEnv();
    $env['PC_MUTATE_FROM'] = $shardOut . DIRECTORY_SEPARATOR . 'mutants.json';
    $env['PC_MUTATE_SHARDS'] = (string) $shards;
    $env['PC_MUTATE_SHARD'] = (string) $index;
    $proc = proc_open(
        [$php, $dir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mutate.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $dir,
        $env
    );
    if (!is_resource($proc)) {
        fwrite(STDERR, "shard {$index} did not start\n");
        exit(1);
    }
    fclose($pipes[0]);
    $procs[] = ['proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2], 'dir' => $shardOut, 'index' => $index];
}

foreach ($procs as $proc) {
    $output = stream_get_contents($proc['out']);
    $error = stream_get_contents($proc['err']);
    fclose($proc['out']);
    fclose($proc['err']);
    $code = proc_close($proc['proc']);
    if (is_string($output) && $output !== '') {
        echo $output;
    }
    if (is_string($error) && $error !== '') {
        fwrite(STDERR, $error);
    }
    $shardFile = $proc['dir'] . DIRECTORY_SEPARATOR . 'shard-' . $proc['index'] . '.json';
    $copied = is_file($shardFile) && copy($shardFile, $out . DIRECTORY_SEPARATOR . 'shard-' . $proc['index'] . '.json');
    if (!$copied) {
        fwrite(STDERR, "shard {$proc['index']} could not be copied\n");
        exit(1);
    }
}

$merge = processEnv();
$merge['PC_MUTATE_MERGE'] = '1';
$merge['PC_MUTATE_SHARDS'] = (string) $shards;
exit(run([$php, $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mutate.php'], $root, $merge));

function clearShardDir(string $dir, string $root): bool
{
    if (!is_dir($dir)) {
        return true;
    }
    run(['git', 'worktree', 'remove', '--force', $dir], $root, processEnv());
    if (!is_dir($dir)) {
        return true;
    }
    run(['git', 'worktree', 'prune'], $root, processEnv());
    if (!dropVendorLink($dir . DIRECTORY_SEPARATOR . 'vendor')) {
        return false;
    }

    return removeTree($dir);
}

function dropVendorLink(string $vendor): bool
{
    if (!file_exists($vendor) && !is_link($vendor) && !is_dir($vendor)) {
        return true;
    }
    if (is_link($vendor)) {
        return unlink($vendor);
    }
    if (PHP_OS_FAMILY === 'Windows' && is_dir($vendor)) {
        return runLine('cmd.exe /d /c rmdir ' . windowsQuote($vendor), dirname($vendor), processEnv()) === 0
            || !is_dir($vendor);
    }

    return is_dir($vendor) ? rmdir($vendor) : unlink($vendor);
}

function removeTree(string $path): bool
{
    if (is_link($path)) {
        return unlink($path);
    }
    if (!is_dir($path)) {
        return unlink($path);
    }
    $items = scandir($path);
    if ($items === false) {
        return false;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (!removeTree($path . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }

    return rmdir($path);
}

function linkVendor(string $vendor, string $link): bool
{
    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'cmd.exe /d /c mklink /J ' . windowsQuote($link) . ' ' . windowsQuote($vendor);

        return runLine($command, dirname($link), processEnv()) === 0 && is_dir($link);
    }

    return symlink($vendor, $link);
}

function windowsQuote(string $path): string
{
    return '"' . str_replace('"', '', $path) . '"';
}

/**
 * @param array<string, string> $env
 */
function runLine(string $command, string $cwd, array $env): int
{
    $pipes = [];

    return finish(proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env), $pipes);
}

/**
 * @param list<string> $command
 * @param array<string, string> $env
 */
function run(array $command, string $cwd, array $env): int
{
    $pipes = [];

    return finish(proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env), $pipes);
}

/**
 * @param array<int, resource> $pipes
 */
function finish(mixed $proc, array $pipes): int
{
    if (!is_resource($proc) || !isset($pipes[0], $pipes[1], $pipes[2])) {
        return 1;
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (is_string($output) && $output !== '') {
        echo $output;
    }
    if (is_string($error) && $error !== '') {
        fwrite(STDERR, $error);
    }

    return proc_close($proc);
}

/**
 * @return array<string, string>
 */
function processEnv(): array
{
    $env = getenv();
    if (!is_array($env)) {
        return [];
    }
    $clean = [];
    foreach ($env as $key => $value) {
        if (is_string($key) && is_string($value)) {
            $clean[$key] = $value;
        }
    }
    unset($clean['PC_MUTATE_LIST'], $clean['PC_MUTATE_FROM'], $clean['PC_MUTATE_SHARD'], $clean['PC_MUTATE_SHARDS'], $clean['PC_MUTATE_MERGE']);

    return $clean;
}
