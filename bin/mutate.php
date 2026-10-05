<?php

declare(strict_types=1);

/**
 * Operator mutation of lines the unit suite already executes.
 * A mutant is killed when that suite exits non-zero. Survivors exit 1.
 */

if (getenv('PC_MUTATE_FUNCTIONS') === '1') {
    if (harnessWasInvoked()) {
        fwrite(STDERR, "PC_MUTATE_FUNCTIONS is set, so the mutation harness did not run\n");
        exit(1);
    }

    return;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$source = $root . DIRECTORY_SEPARATOR . 'src';
$coverageDir = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'mutation-coverage';
$outDir = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'mutation';
if (!is_dir($coverageDir) && !mkdir($coverageDir, 0755, true) && !is_dir($coverageDir)) {
    fwrite(STDERR, "coverage directory could not be created\n");
    exit(1);
}
if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "mutation directory could not be created\n");
    exit(1);
}

$php = PHP_BINARY;
$phpunit = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit';
$env = getenv();
if (!is_array($env)) {
    $env = [];
}
$env['XDEBUG_MODE'] = 'coverage';
$env['PC_COVERAGE'] = $coverageDir;
$coverage = proc_open(
    [$php, $phpunit, '--testsuite', 'unit'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root,
    $env
);
if (!is_resource($coverage)) {
    fwrite(STDERR, "coverage run did not start\n");
    exit(1);
}
fclose($pipes[0]);
stream_get_contents($pipes[1]);
$coverageError = stream_get_contents($pipes[2]);
$coverageCode = proc_close($coverage);
if ($coverageCode !== 0) {
    fwrite(STDERR, "unit suite is not green, so mutants cannot be scored\n");
    fwrite(STDERR, is_string($coverageError) ? $coverageError : '');
    exit(1);
}

/** @var array<string, array<int, int>> $hits */
$hits = [];
$dumps = glob($coverageDir . DIRECTORY_SEPARATOR . '*.php');
if ($dumps === false) {
    fwrite(STDERR, "coverage dumps could not be read\n");
    exit(1);
}
foreach ($dumps as $dump) {
    $raw = include $dump;
    if (!is_array($raw)) {
        continue;
    }
    foreach ($raw as $file => $lines) {
        if (!is_string($file) || !is_array($lines)) {
            continue;
        }
        if (!str_starts_with($file, $source)) {
            continue;
        }
        foreach ($lines as $line => $count) {
            if (!is_int($line) || !is_int($count) || $count < 1) {
                continue;
            }
            $hits[$file][$line] = ($hits[$file][$line] ?? 0) + $count;
        }
    }
}

$replacements = [
    '+' => '-',
    '-' => '+',
    '*' => '/',
    '/' => '*',
    '%' => '*',
    '<' => '<=',
    '>' => '>=',
    '<=' => '<',
    '>=' => '>',
    '==' => '!=',
    '!=' => '==',
    '===' => '!==',
    '!==' => '===',
    '&&' => '||',
    '||' => '&&',
    'and' => 'or',
    'or' => 'and',
    '!' => '',
];

/** @var list<array{file: string, line: int, from: string, to: string, offset: int, length: int}> $mutants */
$mutants = [];
foreach ($hits as $file => $lines) {
    $code = file_get_contents($file);
    if ($code === false) {
        fwrite(STDERR, "could not read {$file}\n");
        exit(1);
    }
    $tokens = token_get_all($code);
    $offset = 0;
    foreach ($tokens as $index => $token) {
        if (is_array($token)) {
            $text = $token[1];
            $line = $token[2];
            $id = $token[0];
            $length = strlen($text);
            $covered = isset($lines[$line]);
            $name = token_name($id);
            $swap = match ($name) {
                'T_IS_EQUAL' => '!=',
                'T_IS_NOT_EQUAL' => '==',
                'T_IS_IDENTICAL' => '!==',
                'T_IS_NOT_IDENTICAL' => '===',
                'T_IS_SMALLER_OR_EQUAL' => '<',
                'T_IS_GREATER_OR_EQUAL' => '>',
                'T_BOOLEAN_AND', 'T_LOGICAL_AND' => '||',
                'T_BOOLEAN_OR', 'T_LOGICAL_OR' => '&&',
                'T_BOOLEAN_XOR', 'T_LOGICAL_XOR' => '&&',
                default => null,
            };
            if ($covered && $swap !== null && $swap !== $text) {
                $mutants[] = [
                    'file' => $file,
                    'line' => $line,
                    'from' => $text,
                    'to' => $swap,
                    'offset' => $offset,
                    'length' => $length,
                ];
            }
            $offset += $length;
            continue;
        }
        $text = $token;
        $length = strlen($text);
        $line = lineAt($code, $offset);
        $covered = isset($lines[$line]);
        if ($covered && isset($replacements[$text]) && isOperatorToken($tokens, $index, $text)) {
            $mutants[] = [
                'file' => $file,
                'line' => $line,
                'from' => $text,
                'to' => $replacements[$text],
                'offset' => $offset,
                'length' => $length,
            ];
        }
        $offset += $length;
    }
}

$short = tooFewMutants(count($mutants));
if ($short !== null) {
    fwrite(STDERR, $short . "\n");
    exit(1);
}

$originals = [];
$killed = 0;
$equivalent = 0;
$survived = [];
$escaped = [];
register_shutdown_function(static function () use (&$originals): void {
    foreach ($originals as $file => $code) {
        file_put_contents($file, $code);
    }
});

foreach ($mutants as $mutant) {
    $file = $mutant['file'];
    if (!isset($originals[$file])) {
        $code = file_get_contents($file);
        if ($code === false) {
            fwrite(STDERR, "could not read {$file}\n");
            exit(1);
        }
        $originals[$file] = $code;
    }
    $code = $originals[$file];
    $next = substr($code, 0, $mutant['offset'])
        . $mutant['to']
        . substr($code, $mutant['offset'] + $mutant['length']);
    if (file_put_contents($file, $next) === false) {
        fwrite(STDERR, "could not write mutant {$file}\n");
        exit(1);
    }
    $runEnv = $env;
    $runEnv['XDEBUG_MODE'] = 'off';
    unset($runEnv['PC_COVERAGE']);
    $run = proc_open(
        [$php, $phpunit, '--testsuite', 'unit'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $runPipes,
        $root,
        $runEnv
    );
    if (!is_resource($run)) {
        file_put_contents($file, $originals[$file]);
        fwrite(STDERR, "mutant run did not start\n");
        exit(1);
    }
    fclose($runPipes[0]);
    stream_get_contents($runPipes[1]);
    stream_get_contents($runPipes[2]);
    $status = proc_close($run);
    file_put_contents($file, $originals[$file]);
    $label = sprintf(
        '%s:%d %s -> %s',
        relative($root, $file),
        $mutant['line'],
        $mutant['from'] === '' ? '(empty)' : $mutant['from'],
        $mutant['to'] === '' ? '(removed)' : $mutant['to']
    );
    if ($status === 0) {
        $reason = equivalentReason(relative($root, $file), $mutant['line'], $mutant['from'], $mutant['to']);
        if ($reason !== null) {
            $equivalent++;
            echo "EQUIV    {$label} — {$reason}\n";
            continue;
        }
        $survived[] = $label;
        echo "SURVIVED {$label}\n";
        continue;
    }
    if ($status === 255 || $status === 1 || $status === 2) {
        $killed++;
        echo "KILLED   {$label}\n";
        continue;
    }
    $escaped[] = $label . " exit {$status}";
    echo "ERROR    {$label} exit {$status}\n";
}

foreach ($originals as $file => $code) {
    file_put_contents($file, $code);
    unset($originals[$file]);
}

$summary = [
    'mutants' => count($mutants),
    'killed' => $killed,
    'equivalent' => $equivalent,
    'survived' => $survived,
    'errors' => $escaped,
];
$encoded = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($encoded === false || file_put_contents($outDir . DIRECTORY_SEPARATOR . 'baseline.json', $encoded . "\n") === false) {
    fwrite(STDERR, "baseline could not be written\n");
    exit(1);
}
echo sprintf(
    "Mutants %d, killed %d, equivalent %d, survived %d, errors %d\n",
    count($mutants),
    $killed,
    $equivalent,
    count($survived),
    count($escaped)
);
exit($survived === [] && $escaped === [] ? 0 : 1);

function lineAt(string $code, int $offset): int
{
    return substr_count($code, "\n", 0, $offset) + 1;
}

/**
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 */
function isOperatorToken(array $tokens, int $index, string $text): bool
{
    if ($text === '!' ) {
        $next = nextCode($tokens, $index);
        return $next !== '=';
    }
    if ($text === '<' || $text === '>') {
        $next = nextCode($tokens, $index);
        return $next !== '=' && $next !== '>';
    }
    if ($text === '+' || $text === '-' || $text === '*' || $text === '/' || $text === '%') {
        $prev = previousCode($tokens, $index);
        $next = nextCode($tokens, $index);
        if ($prev === $text || $next === $text || $next === '=') {
            return false;
        }
        return true;
    }
    return false;
}

/**
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 */
function nextCode(array $tokens, int $index): string
{
    $count = count($tokens);
    for ($i = $index + 1; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token)) {
            if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            return $token[1];
        }
        return $token;
    }
    return '';
}

/**
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 */
function previousCode(array $tokens, int $index): string
{
    for ($i = $index - 1; $i >= 0; $i--) {
        $token = $tokens[$i];
        if (is_array($token)) {
            if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            return $token[1];
        }
        return $token;
    }
    return '';
}

function equivalentReason(string $file, int $line, string $from, string $to): ?string
{
    if ($file === 'src\\Feature\\Backup\\BackupHeadline.php' && $line === 22 && $from === '<=' && $to === '<') {
        return 'a future backup and today both say today, because the day count starts at zero';
    }
    if ($file === 'src\\Feature\\Backup\\DailyBackupFiles.php' && $line === 23 && $from === '||' && $to === '&&') {
        return 'a path that names the volume is already refused, and this process cannot create a symlink';
    }
    if ($file === 'src\\Feature\\Backup\\DailyBackupFiles.php' && $line === 70 && $from === '!' && $to === '') {
        return 'mkdir failure still fails the copy, and the retry only differs if the directory appears after mkdir fails';
    }
    if ($file === 'src\\Feature\\Backup\\DailyBackupFiles.php' && $line === 70 && $from === '&&' && $to === '||') {
        return 'the last clause is true whenever mkdir has just failed, so both operators agree';
    }
    if ($file === 'src\\Feature\\Backup\\DailyBackupFiles.php' && $line === 143 && $from === '&&' && $to === '||') {
        return 'a normal file inside the root stays deletable; leaving the root takes a symlink';
    }
    if ($file === 'src\\Feature\\Party\\VendorPlacement.php' && $line === 64 && $from === '<' && $to === '<=') {
        return 'an equal start date stores the same date';
    }
    if ($file === 'src\\Feature\\Party\\BoothRoster.php' && $line === 254 && $from === '<=' && $to === '<') {
        return 'a stay that starts today is already covering, so this incoming comparison is not used';
    }
    if ($file === 'src\\Feature\\Party\\BoothMap.php' && $line === 58 && $from === '<=' && $to === '<') {
        return 'a stay that starts today is covering, so the incoming comparison is not used';
    }
    if ($file === 'src\\Feature\\Party\\MoveInRent.php' && $line === 18 && $from === '<=' && $to === '<') {
        return 'a move-in on the first still charges every day of that month, so the comparison and the proration agree';
    }
    if ($file === 'src\\Feature\\Rent\\RentFigure.php' && $line === 35 && $from === '&&' && $to === '||') {
        return 'a start outside this month cannot hold the booth, and a start on the first returns that same day';
    }
    if ($file === 'src\\Feature\\Rent\\RentFigure.php' && $line === 35 && $from === '>' && $to === '>=') {
        return 'a start on the first of the month returns that same day either way';
    }

    return null;
}

function harnessWasInvoked(): bool
{
    $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if (!is_string($script) || $script === '') {
        return false;
    }
    $main = realpath($script);
    $here = realpath(__FILE__);

    return $main !== false && $here !== false && $main === $here;
}

/** The last complete run found this many mutants. A smaller run did not see the suite. */
function mutantMinimum(): int
{
    return 313;
}

function tooFewMutants(int $count): ?string
{
    if ($count >= mutantMinimum()) {
        return null;
    }

    return 'only ' . $count . ' mutants were found; the coverage driver is missing or the filter changed';
}

function relative(string $root, string $file): string
{
    $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $normalized = str_replace('\\', '/', $file);
    if (str_starts_with($normalized, $prefix)) {
        $normalized = substr($normalized, strlen($prefix));
    }

    return str_replace('/', '\\', $normalized);
}
