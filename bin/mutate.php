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
if (getenv('PC_MUTATE_MERGE') === '1') {
    exit(mergeShards($outDir));
}

$php = PHP_BINARY;
$phpunit = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit';
$env = getenv();
if (!is_array($env)) {
    $env = [];
}
/** @var list<array{file: string, line: int, from: string, to: string, offset: int, length: int}> $mutants */
$mutants = [];
$fromFile = getenv('PC_MUTATE_FROM');
if (is_string($fromFile) && $fromFile !== '') {
    $mutants = loadMutantList($root, $fromFile);
} else {
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
$coverageOutput = stream_get_contents($pipes[1]);
$coverageError = stream_get_contents($pipes[2]);
$coverageCode = proc_close($coverage);
if ($coverageCode !== 0) {
    fwrite(STDERR, "unit suite is not green, so mutants cannot be scored\n");
    fwrite(STDERR, is_string($coverageOutput) ? $coverageOutput : '');
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
}

$short = tooFewMutants(count($mutants));
if ($short !== null) {
    fwrite(STDERR, $short . "\n");
    exit(1);
}

$listed = mutantList($root, $mutants);
if (getenv('PC_MUTATE_LIST') === '1') {
    $encodedList = json_encode($listed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encodedList === false || file_put_contents($outDir . DIRECTORY_SEPARATOR . 'mutants.json', $encodedList . "\n") === false) {
        fwrite(STDERR, "mutant list could not be written\n");
        exit(1);
    }
    echo 'Mutants ' . count($listed) . "\n";
    exit(0);
}

$shardCount = getenv('PC_MUTATE_SHARDS');
$shardIndex = getenv('PC_MUTATE_SHARD');
$shard = null;
if (is_string($shardCount) && $shardCount !== '') {
    if (!is_string($shardIndex) || preg_match('/^[0-9]+$/', $shardCount) !== 1 || preg_match('/^[0-9]+$/', $shardIndex) !== 1) {
        fwrite(STDERR, "PC_MUTATE_SHARD and PC_MUTATE_SHARDS must be whole numbers\n");
        exit(1);
    }
    $count = (int) $shardCount;
    $index = (int) $shardIndex;
    if ($count < 1 || $index < 0 || $index >= $count) {
        fwrite(STDERR, "PC_MUTATE_SHARD is outside PC_MUTATE_SHARDS\n");
        exit(1);
    }
    $slice = [];
    foreach ($mutants as $at => $mutant) {
        if ($at % $count === $index) {
            $slice[] = $mutant;
        }
    }
    $mutants = $slice;
    $shard = $index;
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
$resultName = $shard === null ? 'baseline.json' : 'shard-' . $shard . '.json';
if ($encoded === false || file_put_contents($outDir . DIRECTORY_SEPARATOR . $resultName, $encoded . "\n") === false) {
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

/**
 * @param list<array{file: string, line: int, from: string, to: string, offset: int, length: int}> $mutants
 * @return list<array{file: string, line: int, from: string, to: string, offset: int, length: int}>
 */
function mutantList(string $root, array $mutants): array
{
    $listed = [];
    foreach ($mutants as $mutant) {
        $mutant['file'] = relative($root, $mutant['file']);
        $listed[] = $mutant;
    }

    return $listed;
}

/**
 * @return list<array{file: string, line: int, from: string, to: string, offset: int, length: int}>
 */
function loadMutantList(string $root, string $path): array
{
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        fwrite(STDERR, "mutant list could not be read\n");
        exit(1);
    }
    $mutants = [];
    foreach ($decoded as $row) {
        if (
            !is_array($row)
            || !is_string($row['file'] ?? null)
            || !is_int($row['line'] ?? null)
            || !is_string($row['from'] ?? null)
            || !is_string($row['to'] ?? null)
            || !is_int($row['offset'] ?? null)
            || !is_int($row['length'] ?? null)
        ) {
            fwrite(STDERR, "mutant list is not usable\n");
            exit(1);
        }
        $file = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $row['file']);
        $mutants[] = [
            'file' => $file,
            'line' => $row['line'],
            'from' => $row['from'],
            'to' => $row['to'],
            'offset' => $row['offset'],
            'length' => $row['length'],
        ];
    }

    return $mutants;
}

function mergeShards(string $outDir): int
{
    $countRaw = getenv('PC_MUTATE_SHARDS');
    if (!is_string($countRaw) || preg_match('/^[0-9]+$/', $countRaw) !== 1 || (int) $countRaw < 1) {
        fwrite(STDERR, "PC_MUTATE_SHARDS is required to merge\n");

        return 1;
    }
    $count = (int) $countRaw;
    $mutants = 0;
    $killed = 0;
    $equivalent = 0;
    $survived = [];
    $errors = [];
    for ($index = 0; $index < $count; $index++) {
        $raw = file_get_contents($outDir . DIRECTORY_SEPARATOR . 'shard-' . $index . '.json');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (
            !is_array($decoded)
            || !is_int($decoded['mutants'] ?? null)
            || !is_int($decoded['killed'] ?? null)
            || !is_int($decoded['equivalent'] ?? null)
            || !is_array($decoded['survived'] ?? null)
            || !is_array($decoded['errors'] ?? null)
        ) {
            fwrite(STDERR, "shard {$index} is missing\n");

            return 1;
        }
        $mutants += $decoded['mutants'];
        $killed += $decoded['killed'];
        $equivalent += $decoded['equivalent'];
        foreach ($decoded['survived'] as $label) {
            if (is_string($label)) {
                $survived[] = $label;
            }
        }
        foreach ($decoded['errors'] as $label) {
            if (is_string($label)) {
                $errors[] = $label;
            }
        }
    }
    $short = tooFewMutants($mutants);
    if ($short !== null) {
        fwrite(STDERR, $short . "\n");

        return 1;
    }
    $summary = [
        'mutants' => $mutants,
        'killed' => $killed,
        'equivalent' => $equivalent,
        'survived' => $survived,
        'errors' => $errors,
    ];
    $encoded = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($outDir . DIRECTORY_SEPARATOR . 'baseline.json', $encoded . "\n") === false) {
        fwrite(STDERR, "baseline could not be written\n");

        return 1;
    }
    echo sprintf(
        "Mutants %d, killed %d, equivalent %d, survived %d, errors %d\n",
        $mutants,
        $killed,
        $equivalent,
        count($survived),
        count($errors)
    );
    foreach ($survived as $label) {
        echo "SURVIVED {$label}\n";
    }

    return $survived === [] && $errors === [] ? 0 : 1;
}

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
    $slash = str_replace('\\', '/', $file);
    if ($slash === 'src/Feature/Inventory/ItemPhotoIntake.php' && $line === 96 && $from === '<' && $to === '<=') {
        return 'a cap equal to the ceiling stores that same ceiling';
    }
    if ($slash === 'src/Feature/Inventory/ItemPhotoIntake.php' && $line === 126 && (($from === '===' && $to === '!==') || ($from === '||' && $to === '&&'))) {
        return 'an empty or unlimited ini value and every other zero parse as no cap';
    }
    if ($slash === 'src/Feature/Inventory/ItemPhotoIntake.php' && $line === 138 && $from === '>' && $to === '>=') {
        return 'zero bytes and a negative parse both mean no cap';
    }
    if ($file === 'src\\Feature\\Backup\\BackupHeadline.php' && $line === 22 && $from === '<=' && $to === '<') {
        return 'a future backup and today both say today, because the day count starts at zero';
    }
    if ($file === 'src\\Feature\\Backup\\DailyBackupFiles.php' && $line === 23 && $from === '||' && $to === '&&') {
        return 'a path that names the volume is already refused, and this process cannot create a symlink';
    }
    if ($file === 'src\\Feature\\Backup\\BackupWorkspace.php' && $line === 21 && $from === '!' && $to === '') {
        return 'mkdir failure still fails the copy, and the retry only differs if the directory appears after mkdir fails';
    }
    if ($file === 'src\\Feature\\Backup\\BackupWorkspace.php' && $line === 21 && $from === '&&' && $to === '||') {
        return 'the last clause is true whenever mkdir has just failed, so both operators agree';
    }
    if ($file === 'src\\Feature\\Backup\\BackupWorkspace.php' && ($line === 48 || $line === 49 || $line === 50) && $from === '&&' && $to === '||') {
        return 'addFile is only reached for a path that is already a file, so a false result is the same failure either way';
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
    if ($file === 'src\\Feature\\Rent\\RentCycle.php' && $line === 79 && $from === '<=' && $to === '<') {
        return 'a move-in on the period start is charged for every day, which is the full amount';
    }
    if ($file === 'src\\Feature\\Rent\\RentFigure.php' && $line === 26 && $from === '>' && $to === '>=') {
        return 'a stay that starts on the pinned day keeps that day';
    }
    if ($file === 'src\\Feature\\Rent\\RentFigure.php' && $line === 47 && $from === '&&' && $to === '||') {
        return 'a start outside this month cannot hold the booth, and a start on the first returns that same day';
    }
    if ($file === 'src\\Feature\\Rent\\RentFigure.php' && $line === 47 && $from === '>' && $to === '>=') {
        return 'a start on the first of the month returns that same day either way';
    }
    if ($file === 'src\\Ledger\\Money.php' && $line === 19 && $from === '>' && $to === '>=') {
        return 'nine digits still fit in an integer, and the dollar cap is only reachable on a 32-bit build';
    }
    if ($file === 'src\\Feature\\Backup\\CommandProcess.php' && $line === 60 && $from === '||' && $to === '&&') {
        return 'proc_open either returns every pipe or fails before this check';
    }
    if ($file === 'src\\Feature\\Backup\\PostgresTools.php' && $line === 152 && $from === '!' && $to === '') {
        return 'a password file that unlink removed is already gone, so the second check agrees';
    }
    if ($file === 'src\\Feature\\Backup\\PostgresTools.php' && $line === 152 && $from === '&&' && $to === '||') {
        return 'a password file that unlink removed is already gone, so the second check agrees';
    }
    if ($file === 'src\\Feature\\Backup\\PostgresTools.php' && $line === 102 && $from === '!' && $to === '') {
        return 'getenv with no name returns the environment array';
    }
    if ($file === 'src\\Schema\\SqlObjects.php' && $line === 102 && $from === '||' && $to === '&&') {
        return 'explode always returns strings, and an empty table name is returned either way';
    }
    if ($file === 'src\\Feature\\Inventory\\LabelSheet.php' && $line === 132 && $from === '+' && $to === '-') {
        return 'the row gap on every stock is zero, so adding it and subtracting it leave the same top';
    }
    if ($file === 'src\\Feature\\Inventory\\LabelSheet.php' && $line === 173 && $from === '<' && $to === '<=') {
        return 'a module width of 2 is already the floor this clamp writes';
    }
    if ($file === 'src\\Feature\\Inventory\\LabelSheet.php' && $line === 176 && $from === '>' && $to === '>=') {
        return 'a module width of 6 is already the cap this clamp writes';
    }
    if ($file === 'src\\Feature\\Inventory\\LabelSheet.php' && $line === 180 && $from === '<' && $to === '<=') {
        return 'a bar height of 8 pixels is already the floor this clamp writes';
    }
    if ($file === 'src\\Feature\\Inventory\\LabelFace.php' && $line === 56 && $from === '>' && $to === '>=') {
        return 'when the leftover room equals the cap, both branches return that cap';
    }
    if ($file === 'src\\Feature\\Inventory\\LabelFace.php' && $line === 138 && $from === '<' && $to === '<=') {
        return 'a usable width of one module is already below the scan floor, and a barcode always has more than one module';
    }
    if ($file === 'src\\Feature\\Inventory\\LabelFace.php' && $line === 138 && $from === '||' && $to === '&&') {
        return 'a barcode always has more than one module, and a negative width still falls below the scan floor';
    }
    if ($file === 'src\\Feature\\Inventory\\LabelFace.php' && $line === 159 && $from === '<' && $to === '<=') {
        return 'a font of one pixel is already the floor this clamp writes';
    }
    if ($file === 'src\\Feature\\Inventory\\LabelFace.php' && $line === 163 && $from === '<' && $to === '<=') {
        return 'a character of one pixel is already the floor this clamp writes';
    }
    if ($file === 'src\\Feature\\Inventory\\LabelFace.php' && $line === 167 && $from === '<' && $to === '<=') {
        return 'four characters are already the floor this clamp writes';
    }
    if ($file === 'src\\Feature\\Rent\\RentBill.php' && $line === 25 && $from === '>' && $to === '>=') {
        return 'an equal total already returned the lines, so this comparison is only reached when the totals differ';
    }
    if ($file === 'src\\Feature\\Rent\\LateFee.php' && $line === 67 && $from === '<' && $to === '<=') {
        return 'a waive on the fee date stores that same date';
    }
    if ($file === 'src\\Feature\\Party\\AssignmentOffer.php' && $line === 102 && $from === '>' && $to === '>=') {
        return 'one pass per stay plus the day after the last end already finds the free day';
    }
    if ($file === 'src\\Feature\\Party\\AssignmentOffer.php' && ($line === 157 || $line === 160) && $from === '===' && $to === '!==') {
        return 'an open stay covers every later day, so the booth stays taken either way';
    }
    if ($file === 'src\\Shared\\Web\\layout.php' && $line === 192 && $from === '&&' && $to === '||') {
        return 'preview mode only lists vendor links, and an empty preview leaves the address unchanged';
    }
    if ($file === 'src\\Feature\\Rent\\RentSpan.php' && $line === 30 && $from === '>=' && $to === '>') {
        return 'day 1 is the fallback, so the strict comparison stores the same day';
    }
    if ($file === 'src\\Feature\\Payout\\PayoutWindow.php' && $line === 16 && $from === '>=' && $to === '>') {
        return 'day 1 is the fallback, so the strict comparison stores the same day';
    }
    if ($file === 'src\\Feature\\Rent\\RentCover.php' && $line === 14 && $from === '>=' && $to === '>') {
        return 'day 1 is the fallback, so the strict comparison stores the same day';
    }
    if ($file === 'src\\Feature\\Mall\\settings.php' && $line === 9 && $from === '>=' && $to === '>') {
        return 'day 1 is the fallback, so the strict comparison stores the same day';
    }
    if ($file === 'src\\Feature\\Pos\\SaleJournal.php' && ($line === 98 || $line === 115) && $from === '>' && $to === '>=') {
        return 'a zero cash difference is not posted, and both branches would post a zero line';
    }
    if ($file === 'src\\Feature\\Pos\\TicketTenders.php' && $line === 169 && $from === '<' && $to === '<=') {
        return 'a zero remainder already returned, so this comparison only sees a remainder that is not zero';
    }
    if ($file === 'src\\Feature\\Pos\\LayawayBook.php' && $line === 244 && $from === '||' && $to === '&&') {
        return 'hold already refused an empty ticket, a zero amount, and an unknown tender, so this guard is never true';
    }
    if ($file === 'src\\Ledger\\LedgerPeriods.php' && $line === 52 && $from === '<' && $to === '<=') {
        return 'the third failed insert throws before the loop condition is tested again';
    }
    if ($file === 'src\\Ledger\\LedgerPeriods.php' && $line === 87 && $from === '>' && $to === '>=') {
        return 'the day after the previous period is already the start, so assigning it changes nothing';
    }
    if ($file === 'src\\Ledger\\LedgerPeriods.php' && $line === 100 && $from === '<' && $to === '<=') {
        return 'the day before the next period is already the end, so assigning it changes nothing';
    }
    if ($file === 'src\\Shared\\Web\\layout.php' && $line === 220 && $from === '&&' && $to === '||') {
        return 'a vendor preview only lists vendor links, and an empty preview leaves the address unchanged';
    }
    if ($file === 'src\\Feature\\Register\\RegisterSession.php' && $line === 112 && $from === '>' && $to === '>=') {
        return 'a zero difference returns before the variance lines are chosen';
    }
    if ($file === 'src\\Feature\\Backup\\NightlyCopy.php' && $line === 73 && $from === '>' && $to === '>=') {
        return 'two backup names cannot be the same day, so the equal case does not exist';
    }
    if ($file === 'src\\Feature\\Payout\\PayoutRunController.php' && $line === 107 && $from === '&&' && $to === '||') {
        return 'a missing or blank reversal time formats as an empty local time either way';
    }
    if ($file === 'src\\Feature\\Payout\\envelope.php' && $line === 27 && $from === '&&' && $to === '||') {
        return 'a missing vendor row is not an array, so both operators skip the code';
    }
    if ($file === 'src\\Feature\\Vendor\\VendorSales.php' && $line === 84 && $from === '>' && $to === '>=') {
        return 'a start of today is already today, so assigning it changes nothing';
    }
    if ($file === 'src\\Feature\\Vendor\\VendorCheck.php' && $line === 115 && $from === '>' && $to === '>=') {
        return 'nothing owed is already zero, so the equal case stores the same desk amount';
    }
    if ($file === 'src\\Feature\\Payout\\PayoutStore.php' && $line === 253 && $from === '>=' && $to === '>') {
        return 'day 1 is the fallback, and the payout window clamps that same day again';
    }
    if ($file === 'src\\Feature\\Payout\\PayoutStore.php' && $line === 253 && $from === '&&' && $to === '||') {
        return 'a day outside 1 through 28 is clamped to day 1 again by the payout window';
    }
    if ($file === 'src\\Feature\\Mall\\MallPayoutSettings.php' && $line === 82 && $from === '>=' && $to === '>') {
        return 'a grace of zero is the fallback, so the strict comparison stores the same grace';
    }
    if ($file === 'src\\Feature\\Mall\\settings.php' && $line === 10 && $from === '>=' && $to === '>') {
        return 'day 1 is the fallback, so the strict comparison stores the same day';
    }
    if ($file === 'src\\Shared\\Mail\\SmtpClient.php' && $line === 41 && $from === '||' && $to === '&&') {
        return 'a failed connect sets the socket false together with errno and the error text, and a live socket has neither';
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
