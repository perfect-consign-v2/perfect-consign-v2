<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Node\File;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$directory = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'coverage';
$minimum = 90.0;
$filter = new Filter();
$source = $root . DIRECTORY_SEPARATOR . 'src';
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source));
foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getRealPath();
    if ($path === false) {
        continue;
    }
    $filter->includeFile($path);
}
$coverage = new CodeCoverage((new Selector())->forLineCoverage(new Filter()), $filter);
$coverage->disableCheckForUnintentionallyCoveredCode();
$coverage->includeUncoveredFiles();
$phpDumps = glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [];
$covDumps = glob($directory . DIRECTORY_SEPARATOR . '*.cov') ?: [];
$dumps = array_merge($phpDumps, $covDumps);
if ($dumps === []) {
    fwrite(STDERR, "no coverage dumps in {$directory}\n");
    exit(1);
}
$merged = [];
foreach ($dumps as $dump) {
    if (str_ends_with($dump, '.cov')) {
        $encoded = file_get_contents($dump);
        if ($encoded === false) {
            fwrite(STDERR, "coverage dump could not be read: {$dump}\n");
            exit(1);
        }
        $raw = unserialize($encoded, ['allowed_classes' => false]);
    } else {
        $raw = include $dump;
    }
    if (!is_array($raw) || $raw === []) {
        continue;
    }
    foreach ($raw as $file => $lines) {
        if (!is_string($file) || !is_array($lines)) {
            continue;
        }
        if (!isset($merged[$file])) {
            $merged[$file] = [];
        }
        foreach ($lines as $line => $hits) {
            if ((!is_int($line) && !is_string($line)) || !is_int($hits)) {
                continue;
            }
            $lineNo = (int) $line;
            $prior = $merged[$file][$lineNo] ?? null;
            if ($prior === null || $hits > $prior) {
                $merged[$file][$lineNo] = $hits;
            }
        }
    }
}
if ($merged === []) {
    fwrite(STDERR, "coverage dumps were empty in {$directory}\n");
    exit(1);
}
$coverage->append(RawCodeCoverageData::fromLineCoverage($merged), 'merged');
$report = $coverage->getReport();
$executed = $report->numberOfExecutedLines();
$executable = $report->numberOfExecutableLines();
$percent = $executable === 0 ? 0.0 : ($executed / $executable) * 100;
echo sprintf("Lines: %d/%d (%.2f%%)\n", $executed, $executable, $percent);
$rows = [];
foreach ($report as $child) {
    if (!$child instanceof File) {
        continue;
    }
    $fileExecutable = $child->numberOfExecutableLines();
    $fileExecuted = $child->numberOfExecutedLines();
    $rows[] = [
        'name' => $child->id(),
        'executed' => $fileExecuted,
        'executable' => $fileExecutable,
        'missed' => $fileExecutable - $fileExecuted,
        'percent' => $fileExecutable === 0 ? 100.0 : ($fileExecuted / $fileExecutable) * 100,
    ];
}
usort($rows, static function (array $left, array $right): int {
    return $right['missed'] <=> $left['missed'];
});
$shown = 0;
foreach ($rows as $row) {
    if ($row['missed'] === 0) {
        continue;
    }
    echo sprintf(
        "%6.1f%% %4d/%4d %s\n",
        $row['percent'],
        $row['executed'],
        $row['executable'],
        $row['name']
    );
    $shown++;
    if ($shown === 30) {
        break;
    }
}
if ($percent + 0.001 < $minimum) {
    fwrite(STDERR, sprintf("line coverage %.2f%% is below %.0f%%\n", $percent, $minimum));
    exit(1);
}
