<?php

declare(strict_types=1);

function runObligations(string $root): void
{
$sqlDirectory = $root . DIRECTORY_SEPARATOR . 'sql';
$failures = [];

$chartProof = readSql($sqlDirectory . DIRECTORY_SEPARATOR . 'prove_chart.sql');
$itemProof = readSql($sqlDirectory . DIRECTORY_SEPARATOR . 'prove_append_only_guard.sql');
$vendorProof = readSql($sqlDirectory . DIRECTORY_SEPARATOR . 'prove_vendors_booths.sql');
$expectedRoles = proofRoles($chartProof);

$ledgerFiles = glob($sqlDirectory . DIRECTORY_SEPARATOR . '0*.sql');
if ($ledgerFiles === false) {
    fwrite(STDERR, "could not list ledger files\n");
    exit(1);
}
sort($ledgerFiles);

foreach ($ledgerFiles as $path) {
    $file = basename($path);
    $sql = readSql($path);
    foreach (chartRoles($sql) as $role) {
        if (!in_array($role, $expectedRoles, true)) {
            $failures[] = $file . ' adds chart role ' . $role . ' and prove_chart.sql does not list it';
        }
    }
    if ($file > '012_export.sql' && chartRoles($sql) !== [] && !str_contains($sql, 'stall_seed_export_accounts')) {
        $failures[] = $file . ' adds a chart role and does not call stall_seed_export_accounts()';
    }
    foreach (referenceConstraints($sql, 'items') as $constraint) {
        if (!droppedBeforeTruncate($itemProof, $constraint, 'items')) {
            $failures[] = $file . ' references items by ' . $constraint . ' and prove_append_only_guard.sql does not drop it before TRUNCATE items';
        }
    }
    foreach (referenceConstraints($sql, 'vendors') as $constraint) {
        if (!droppedBeforeTruncate($vendorProof, $constraint, 'vendors')) {
            $failures[] = $file . ' references vendors by ' . $constraint . ' and prove_vendors_booths.sql does not drop it before TRUNCATE vendors';
        }
    }
    if (str_contains($sql, 'SET event_triggers')) {
        $failures[] = $file . ' sets event_triggers';
    }
    if (str_contains($sql, 'ADD COLUMN IF NOT EXISTS')) {
        $failures[] = $file . ' adds a column with IF NOT EXISTS';
    }
    if (str_contains($sql, 'DROP CONSTRAINT IF EXISTS')) {
        $failures[] = $file . ' drops a constraint with IF EXISTS';
    }
}

foreach (duplicateFunctions($sqlDirectory) as $duplicate) {
    $failures[] = $duplicate;
}

foreach (prorationCopies($root . DIRECTORY_SEPARATOR . 'src') as $copy) {
    $failures[] = $copy;
}

foreach (unprovenSymbols($root) as $symbol) {
    $failures[] = $symbol;
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "ok obligations\n");
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === realpath(__FILE__)) {
    try {
        runObligations(dirname(__DIR__));
    } catch (RuntimeException $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}

function symbolIsCovered(string $name, string $covered): bool
{
    $calls = preg_replace('/\\bfunction\\s+' . preg_quote($name, '/') . '\\s*\\(/', '', $covered);
    if (!is_string($calls)) {
        return false;
    }

    return preg_match('/\\b' . preg_quote($name, '/') . '\\s*\\(/', $calls) === 1;
}

function identifierIsCovered(string $name, string $covered): bool
{
    return preg_match('/\\b' . preg_quote($name, '/') . '\\b/', $covered) === 1;
}

function obligationBase(string $root): string
{
    $given = getenv('OBLIGATION_BASE');
    if (is_string($given) && $given !== '') {
        return $given;
    }
    $merged = gitResult($root, ['merge-base', 'HEAD', 'origin/master']);
    $base = trim($merged['stdout']);
    if ($merged['code'] === 0 && $base !== '') {
        return $base;
    }
    throw new RuntimeException('obligation base is missing');
}

/**
 * @return list<string>
 */
function duplicateFunctions(string $sqlDirectory): array
{
    $files = glob($sqlDirectory . DIRECTORY_SEPARATOR . '*.sql');
    if ($files === false) {
        fwrite(STDERR, "could not list sql files\n");
        exit(1);
    }
    sort($files);
    $defined = [];
    $failures = [];
    foreach ($files as $path) {
        $file = basename($path);
        if (str_starts_with($file, 'prove_')) {
            continue;
        }
        $sql = readSql($path);
        $seen = [];
        if (preg_match_all(
            '/CREATE\\s+(?:OR\\s+REPLACE\\s+)?FUNCTION\\s+((?:[a-z_][a-z0-9_]*\\.)?[a-z_][a-z0-9_]*)\\s*\\(/i',
            $sql,
            $matches
        ) === false) {
            continue;
        }
        foreach ($matches[1] as $name) {
            if (!is_string($name)) {
                continue;
            }
            $key = strtolower($name);
            if (isset($seen[$key])) {
                $failures[] = $file . ' creates ' . $name . ' twice';
            }
            $seen[$key] = true;
            if (isset($defined[$key])) {
                $failures[] = $file . ' creates ' . $name . ' and ' . $defined[$key] . ' already creates it';
                continue;
            }
            $defined[$key] = $file;
        }
    }

    return $failures;
}

function readSql(string $path): string
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        fwrite(STDERR, "could not read $path\n");
        exit(1);
    }

    return $sql;
}

/**
 * @return list<string>
 */
function proofRoles(string $sql): array
{
    $roles = [];
    if (preg_match_all("/\\('([a-z][a-z0-9_]*)',/", $sql, $matches) !== false) {
        foreach ($matches[1] as $role) {
            $roles[] = $role;
        }
    }

    return array_values(array_unique($roles));
}

/**
 * @return list<string>
 */
function chartRoles(string $sql): array
{
    $bare = stripComments($sql);
    $roles = [];
    if (preg_match_all('/INSERT INTO chart_roles\\b.*?;/s', $bare, $inserts) === false) {
        return [];
    }
    foreach ($inserts[0] as $insert) {
        if (preg_match_all("/'([a-z][a-z0-9_]*)'/", $insert, $matches) === false) {
            continue;
        }
        foreach ($matches[1] as $role) {
            $roles[] = $role;
        }
    }

    return array_values(array_unique($roles));
}

/**
 * @return list<string>
 */
function referenceConstraints(string $sql, string $target): array
{
    $bare = stripComments($sql);
    $names = [];
    if (preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\\s+([a-z_]+)\\s*\\(/i', $bare, $tables, PREG_OFFSET_CAPTURE) !== false) {
        foreach ($tables[1] as $index => $table) {
            $open = $tables[0][$index][1] + strlen($tables[0][$index][0]) - 1;
            $body = parentBody($bare, $open);
            if ($body === null) {
                continue;
            }
            foreach (columnReferences($body, $table[0], $target) as $name) {
                $names[] = $name;
            }
        }
    }
    if (preg_match_all('/ALTER TABLE\\s+([a-z_]+)\\b[^;]*;/is', $bare, $alters) !== false) {
        foreach ($alters[0] as $index => $statement) {
            foreach (columnReferences($statement, $alters[1][$index], $target) as $name) {
                $names[] = $name;
            }
        }
    }

    return array_values(array_unique($names));
}

/**
 * @return list<string>
 */
function columnReferences(string $body, string $table, string $target): array
{
    $names = [];
    if (preg_match_all(
        '/CONSTRAINT\\s+([a-z0-9_]+)\\s+FOREIGN KEY\\s*\\(([^)]+)\\)\\s+REFERENCES\\s+' . $target . '\\b/i',
        $body,
        $named
    ) !== false) {
        foreach ($named[1] as $name) {
            $names[] = $name;
        }
    }
    if (preg_match_all(
        '/\\b([a-z_][a-z0-9_]*)\\s+uuid\\b[^,;]*REFERENCES\\s+' . $target . '\\b/i',
        $body,
        $columns
    ) !== false) {
        foreach ($columns[1] as $column) {
            $names[] = $table . '_' . $column . '_fkey';
        }
    }

    return $names;
}

function droppedBeforeTruncate(string $proof, string $constraint, string $table): bool
{
    if (preg_match_all('/^\\s*TRUNCATE\\s+[^;]+/mi', $proof, $truncates, PREG_OFFSET_CAPTURE) === false) {
        return false;
    }
    $found = false;
    foreach ($truncates[0] as $truncate) {
        if (preg_match('/\\b' . $table . '\\b/', $truncate[0]) !== 1) {
            continue;
        }
        $found = true;
        $before = substr($proof, 0, $truncate[1]);
        if (!str_contains($before, 'DROP CONSTRAINT ' . $constraint)) {
            return false;
        }
    }

    return $found;
}

function parentBody(string $sql, int $open): ?string
{
    if (!isset($sql[$open]) || $sql[$open] !== '(') {
        return null;
    }
    $depth = 0;
    $length = strlen($sql);
    for ($index = $open; $index < $length; $index++) {
        $character = $sql[$index];
        if ($character === '(') {
            $depth++;
            continue;
        }
        if ($character !== ')') {
            continue;
        }
        $depth--;
        if ($depth === 0) {
            return substr($sql, $open + 1, $index - $open - 1);
        }
    }

    return null;
}

function stripComments(string $sql): string
{
    $stripped = preg_replace('/--.*$/m', '', $sql);

    return is_string($stripped) ? $stripped : $sql;
}

/**
 * @return list<string>
 */
function prorationCopies(string $src): array
{
    $failures = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $code = file_get_contents($file->getPathname());
        if ($code === false) {
            $failures[] = 'could not read ' . $file->getPathname();
            continue;
        }
        $relative = substr($file->getPathname(), strlen(dirname($src)) + 1);
        if (preg_match('/function\\s+(shares|percentShares|amountShares)\\s*\\(/', $code) === 1) {
            $failures[] = $relative . ' prorates a line discount in PHP';
        }
        if (preg_match('/intdiv\\s*\\(\\s*\\$price\\s*\\*/', $code) === 1) {
            $failures[] = $relative . ' prorates a line discount in PHP';
        }
        if (preg_match('/\\$value\\s*-\\s*\\$running/', $code) === 1) {
            $failures[] = $relative . ' prorates a line discount in PHP';
        }
    }

    return $failures;
}

/**
 * @return list<string>
 */
function unprovenSymbols(string $root): array
{
    $added = addedLines($root);
    if ($added === []) {
        return [];
    }
    $proofs = '';
    $proofFiles = glob($root . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'prove_*.sql');
    if ($proofFiles !== false) {
        foreach ($proofFiles as $path) {
            $proofs .= "\n" . readSql($path);
        }
    }
    $tests = '';
    $testFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . DIRECTORY_SEPARATOR . 'tests')
    );
    foreach ($testFiles as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $code = file_get_contents($file->getPathname());
        if ($code !== false) {
            $tests .= "\n" . $code;
        }
    }
    $covered = $proofs . "\n" . $tests;
    $failures = [];
    foreach ($added as $file => $lines) {
        $text = implode("\n", $lines);
        if (str_starts_with($file, 'src/') || str_starts_with($file, 'src\\')) {
            if (preg_match_all('/(?:public|protected|private)\\s+(?:static\\s+)?function\\s+([A-Za-z_][A-Za-z0-9_]*)\\s*\\(/', $text, $methods) !== false) {
                foreach ($methods[1] as $method) {
                    if (!symbolIsCovered($method, $covered)) {
                        $failures[] = $file . ' adds ' . $method . ' and no proof or test names it';
                    }
                }
            }
        }
        if (preg_match('/^sql[\\\\\\/]0/', $file) !== 1) {
            continue;
        }
        if (preg_match_all("/'([a-z][a-z0-9_]*)',\\s*'01900000-[0-9a-f-]{36}'/", $text, $roles) !== false) {
            foreach ($roles[1] as $role) {
                if (!identifierIsCovered($role, $covered)) {
                    $failures[] = $file . ' adds chart role ' . $role . ' and no proof or test names it';
                }
            }
        }
        if (!str_contains(fileText($root, $file), 'chart_source_rules')) {
            continue;
        }
        if (preg_match_all("/\\('([a-z][a-z0-9_]*)',\\s*'([a-z][a-z0-9_]*)'\\)/", $text, $sources) !== false) {
            foreach ($sources[2] as $source) {
                if (!identifierIsCovered($source, $covered)) {
                    $failures[] = $file . ' adds source type ' . $source . ' and no proof or test names it';
                }
            }
        }
    }

    return $failures;
}

/**
 * @return array<string, list<string>>
 */
function addedLines(string $root): array
{
    $diff = git($root, ['diff', '-U0', obligationBase($root), '--', 'src', 'sql']);
    $lines = [];
    $file = '';
    foreach (preg_split("/\\r\\n|\\n|\\r/", $diff) ?: [] as $line) {
        if (str_starts_with($line, '+++ b/')) {
            $file = substr($line, 6);
            continue;
        }
        if ($file === '' || !str_starts_with($line, '+') || str_starts_with($line, '+++')) {
            continue;
        }
        $lines[$file][] = substr($line, 1);
    }
    $untracked = git($root, ['ls-files', '--others', '--exclude-standard', '--', 'src', 'sql']);
    foreach (preg_split("/\\r\\n|\\n|\\r/", trim($untracked)) ?: [] as $path) {
        if ($path === '') {
            continue;
        }
        $text = fileText($root, $path);
        $lines[$path] = preg_split("/\\r\\n|\\n|\\r/", $text) ?: [];
    }

    return $lines;
}

function fileText(string $root, string $relative): string
{
    $path = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    $text = file_get_contents($path);

    return $text === false ? '' : $text;
}

/**
 * @param list<string> $arguments
 */
function git(string $root, array $arguments): string
{
    $result = gitResult($root, $arguments);
    if ($result['code'] !== 0) {
        throw new RuntimeException('could not run git');
    }

    return $result['stdout'];
}

/**
 * @param list<string> $arguments
 * @return array{code: int, stdout: string}
 */
function gitResult(string $root, array $arguments): array
{
    $command = array_merge(['git', '-C', $root], $arguments);
    $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $spec, $pipes);
    if (!is_resource($process)) {
        return ['code' => 1, 'stdout' => ''];
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    return ['code' => $code, 'stdout' => is_string($stdout) ? $stdout : ''];
}
