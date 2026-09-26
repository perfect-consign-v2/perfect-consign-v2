<?php

declare(strict_types=1);

/**
 * Fail if any PHP file in src/ exceeds a configurable max line count.
 * Default limit: 500 lines. Configure via the CHECK_MAX_LINES env var or
 * the first CLI argument (CLI wins).
 *
 * Usage:
 *   php bin/check-max-lines.php            # uses 500 (default)
 *   php bin/check-max-lines.php 400        # stricter limit
 *   CHECK_MAX_LINES=400 php bin/check-max-lines.php
 *
 * This is a god-class guard. CI fails here instead of at review time.
 */

$root = dirname(__DIR__);
chdir($root);

$defaultLimit = 500;
$envLimitRaw = getenv('CHECK_MAX_LINES');
$envLimit = is_string($envLimitRaw) && ctype_digit($envLimitRaw) ? (int) $envLimitRaw : null;
$cliLimit = isset($argv[1]) && ctype_digit($argv[1]) ? (int) $argv[1] : null;
$limit = $cliLimit ?? $envLimit ?? $defaultLimit;

if ($limit < 50) {
    fwrite(STDERR, "Refusing absurdly small limit ({$limit}).\n");
    exit(2);
}

$scanDirs = ['src'];
$violations = [];

foreach ($scanDirs as $dir) {
    $abs = $root . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($abs)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo) {
            continue;
        }
        if (!$entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
            continue;
        }
        $path = $entry->getPathname();
        $lines = 0;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            fwrite(STDERR, "Could not read {$path}\n");
            exit(2);
        }
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false) {
                    break;
                }
                $lines += substr_count($chunk, "\n");
            }
        } finally {
            fclose($handle);
        }

        if ($lines > $limit) {
            $rel = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
            $violations[$rel] = $lines;
        }
    }
}

if ($violations === []) {
    echo "All PHP files under " . implode(', ', $scanDirs) . " are within {$limit} lines.\n";
    exit(0);
}

arsort($violations, SORT_NUMERIC);
fwrite(STDERR, "PHP files exceeding {$limit} lines:\n");
foreach ($violations as $path => $lines) {
    fwrite(STDERR, "  {$lines} lines  {$path}\n");
}
fwrite(
    STDERR,
    "\nSplit each file into single-responsibility units, or raise the\n"
    . "limit explicitly via CHECK_MAX_LINES once the trade-off has been discussed.\n",
);
exit(1);
