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
$dumps = glob($directory . DIRECTORY_SEPARATOR . '*.php');
if ($dumps === false || $dumps === []) {
    fwrite(STDERR, "no coverage dumps in {$directory}\n");
    exit(1);
}
foreach ($dumps as $dump) {
    $raw = include $dump;
    if (!is_array($raw) || $raw === []) {
        continue;
    }
    $coverage->append(RawCodeCoverageData::fromLineCoverage($raw), $dump);
}
$report = $coverage->getReport();
$executed = $report->numberOfExecutedLines();
$executable = $report->numberOfExecutableLines();
$percent = $executable === 0 ? 0.0 : ($executed / $executable) * 100;
echo sprintf("Lines: %d/%d (%.2f%%)\n", $executed, $executable, $percent);
foreach ($report as $child) {
    if (!$child instanceof File) {
        continue;
    }
    $missed = [];
    foreach ($child->lineCoverageData() as $line => $hits) {
        if (is_array($hits) && $hits === []) {
            $missed[] = $line;
        }
    }
    if ($missed === []) {
        continue;
    }
    $name = $child->id();
    echo $name . ' ' . count($missed) . "\n";
    echo '  ' . implode(',', $missed) . "\n";
}
