<?php

declare(strict_types=1);

// One racer for SignInLimitsTest: waits for the shared start time, then tries
// one wrong password for the account. Prints "wrong" or "locked".

use PerfectConsign\Bootstrap\SignInAccount;
use PerfectConsign\Bootstrap\SignInCheck;
use PerfectConsign\Bootstrap\SignInLocked;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Tests\Support\SignInTestKit;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once $root . '/bin/load-env.php';
loadStallEnv($root . '/.env');

[, $tenant, $username, $address, $start] = $argv;
$pdo = new PDO(
    'pgsql:host=' . getenv('STALL_DB_HOST') . ';port=' . getenv('STALL_DB_PORT') . ';dbname=' . getenv('STALL_DB_NAME'),
    (string) getenv('STALL_DB_USER'),
    (string) getenv('STALL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$gate = SignInTestKit::gate($pdo, new Request('POST', '/', [], [], '', '', $address));
$wait = (float) $start - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1000000));
}
try {
    $gate->attempt(SignInAccount::store($tenant, $username), 'wrong', static fn (): SignInCheck => SignInCheck::Wrong);
    echo 'wrong';
} catch (SignInLocked) {
    echo 'locked';
}
