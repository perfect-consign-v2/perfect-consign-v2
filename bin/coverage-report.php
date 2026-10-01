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
