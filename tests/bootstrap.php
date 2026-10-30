<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/bin/load-env.php';

loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');
installTestMailbox();

function installTestMailbox(): void
{
    $key = getenv('STALL_MAIL_KEY');
    if (!is_string($key) || $key === '') {
        putenv('STALL_MAIL_KEY=' . sodium_bin2base64(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES), SODIUM_BASE64_VARIANT_ORIGINAL));
    }
    $address = getenv('STALL_GMAIL_ADDRESS');
    if (!is_string($address) || $address === '') {
        putenv('STALL_GMAIL_ADDRESS=ci-mailbox@example.test');
    }
    $password = getenv('STALL_GMAIL_APP_PASSWORD');
    if (!is_string($password) || $password === '') {
        putenv('STALL_GMAIL_APP_PASSWORD=ci-mailbox-password');
    }
}

$coverage = getenv('PC_COVERAGE');
if (!is_string($coverage) || $coverage === '') {
    return;
}
if (!is_dir($coverage) && !mkdir($coverage, 0755, true) && !is_dir($coverage)) {
    fwrite(STDERR, "coverage directory could not be created\n");
    exit(1);
}
if (getenv('PC_MUTATE_COVERING') === '1') {
    return;
}
if (!function_exists('xdebug_start_code_coverage') || !defined('XDEBUG_FILTER_CODE_COVERAGE')) {
    return;
}
xdebug_set_filter(
    XDEBUG_FILTER_CODE_COVERAGE,
    XDEBUG_PATH_INCLUDE,
    [dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src']
);
xdebug_start_code_coverage();
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
        $coverage . DIRECTORY_SEPARATOR . 'process-' . bin2hex(random_bytes(8)) . '.php',
        '<?php return ' . var_export($kept, true) . ";\n"
    );
    if ($written === false) {
        fwrite(STDERR, "coverage dump failed\n");
    }
});
