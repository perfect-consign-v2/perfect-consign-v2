<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath((string) $argv[0]) !== realpath(__FILE__)) {
    return;
}

require_once __DIR__ . '/load-env.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

use PerfectConsign\Feature\Password\PasswordChoice;

$email = getenv('STALL_PLATFORM_ADMIN_EMAIL');
$password = getenv('STALL_PLATFORM_ADMIN_PASSWORD');
if (!is_string($email) || trim($email) === '') {
    fwrite(STDERR, "STALL_PLATFORM_ADMIN_EMAIL is required\n");
    exit(1);
}
if (!is_string($password) || $password === '') {
    fwrite(STDERR, "STALL_PLATFORM_ADMIN_PASSWORD is required\n");
    exit(1);
}
$email = strtolower(trim($email));
if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1 || strlen($email) > 200) {
    fwrite(STDERR, "STALL_PLATFORM_ADMIN_EMAIL is not a usable email\n");
    exit(1);
}

$host = getenv('STALL_DB_HOST');
$port = getenv('STALL_DB_PORT');
$name = getenv('STALL_DB_NAME');
$user = getenv('STALL_DB_USER');
$adminPassword = getenv('STALL_DB_PASSWORD');
if (
    !is_string($host) || $host === ''
    || !is_string($port) || $port === ''
    || !is_string($name) || $name === ''
    || !is_string($user) || $user === ''
    || !is_string($adminPassword) || $adminPassword === ''
) {
    fwrite(STDERR, "STALL_DB_HOST, STALL_DB_PORT, STALL_DB_NAME, STALL_DB_USER, and STALL_DB_PASSWORD are required\n");
    exit(1);
}

try {
    $hash = (new PasswordChoice())->hash($password);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

$pdo = new PDO(
    'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
    $user,
    $adminPassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$statement = $pdo->prepare(
    'INSERT INTO platform.admins (id, email, password_hash)
     VALUES (uuidv7(), ?, ?)
     ON CONFLICT (email) DO UPDATE
        SET password_hash = EXCLUDED.password_hash,
            credentials_version = platform.admins.credentials_version + 1'
);
$statement->execute([$email, $hash]);
fwrite(STDOUT, "platform admin saved\n");
