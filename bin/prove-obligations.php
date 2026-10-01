<?php

declare(strict_types=1);

$root = dirname(__DIR__);
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
            if (preg_match_all('/public\\s+(?:static\\s+)?function\\s+([A-Za-z_][A-Za-z0-9_]*)\\s*\\(/', $text, $methods) !== false) {
                foreach ($methods[1] as $method) {
                    if (!str_contains($covered, $method)) {
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
                if (!str_contains($covered, $role)) {
                    $failures[] = $file . ' adds chart role ' . $role . ' and no proof or test names it';
                }
            }
        }
        if (!str_contains(fileText($root, $file), 'chart_source_rules')) {
            continue;
        }
        if (preg_match_all("/\\('([a-z][a-z0-9_]*)',\\s*'([a-z][a-z0-9_]*)'\\)/", $text, $sources) !== false) {
            foreach ($sources[2] as $source) {
                if (!str_contains($covered, $source)) {
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
    $diff = git($root, ['diff', '-U0', 'HEAD', '--', 'src', 'sql']);
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
    $command = array_merge(['git', '-C', $root], $arguments);
    $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $spec, $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, "could not run git\n");
        exit(1);
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0 || !is_string($stdout)) {
        fwrite(STDERR, "could not run git\n");
        exit(1);
    }

    return $stdout;
}
