<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$coverage = getenv('PC_COVERAGE');
if (!is_string($coverage) || $coverage === '') {
    return;
}
if (!is_dir($coverage) && !mkdir($coverage, 0755, true) && !is_dir($coverage)) {
    fwrite(STDERR, "coverage directory could not be created\n");
    exit(1);
}
$existing = glob($coverage . DIRECTORY_SEPARATOR . '*.php');
if ($existing === false) {
    fwrite(STDERR, "coverage directory could not be read\n");
    exit(1);
}
foreach ($existing as $old) {
    if (is_file($old) && !unlink($old)) {
        fwrite(STDERR, "coverage dump could not be cleared\n");
        exit(1);
    }
}
if (!function_exists('xdebug_start_code_coverage') || !defined('XDEBUG_FILTER_CODE_COVERAGE')) {
    return;
}
xdebug_set_filter(
    XDEBUG_FILTER_CODE_COVERAGE,
    XDEBUG_PATH_INCLUDE,
    [dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src']
);
xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
register_shutdown_function(static function () use ($coverage): void {
    if (!function_exists('xdebug_get_code_coverage')) {
        return;
    }
    $data = xdebug_get_code_coverage();
    xdebug_stop_code_coverage(false);
    if (!is_array($data)) {
        return;
    }
    $src = strtolower(str_replace('\\', '/', dirname(__DIR__) . '/src/'));
    $kept = [];
    foreach ($data as $file => $lines) {
        if (!is_string($file) || !is_array($lines)) {
            continue;
        }
        $real = realpath($file);
        if ($real === false) {
            continue;
        }
        $key = strtolower(str_replace('\\', '/', $real));
        if (!str_starts_with($key, $src)) {
            continue;
        }
        $kept[$real] = $lines;
    }
    $written = file_put_contents(
        $coverage . DIRECTORY_SEPARATOR . 'process.php',
        '<?php return ' . var_export($kept, true) . ";\n"
    );
    if ($written === false) {
        fwrite(STDERR, "coverage dump failed\n");
    }
});
