<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

require_once __DIR__ . '/load-env.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

use PerfectConsign\Feature\Dev\OwnerCredentialPlant;
use PerfectConsign\Feature\Dev\OwnerPlantOutcome;

$slug = $argv[1] ?? '';
$username = $argv[2] ?? '';
$password = getenv('STALL_OWNER_PASSWORD');
if (!is_string($password) || $password === '') {
    fwrite(STDERR, "STALL_OWNER_PASSWORD is required\n");
    exit(1);
}
$email = ownerEmail(getenv('STALL_OWNER_EMAIL'));
if (!is_string($slug) || $slug === '' || !is_string($username) || $username === '') {
    fwrite(STDERR, "usage: php bin/set-owner.php <slug> <username>\n");
    exit(1);
}

try {
    $outcome = (new OwnerCredentialPlant(plantPdo()))->plant($slug, $username, $password, $email);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, match ($outcome) {
    OwnerPlantOutcome::Unchanged => "owner unchanged\n",
    OwnerPlantOutcome::ProfileSaved => "owner profile saved\n",
    OwnerPlantOutcome::Saved => "owner saved\n",
});

/**
 * @return PDO
 */
function plantPdo(): PDO
{
    $host = getenv('STALL_DB_HOST');
    $port = getenv('STALL_DB_PORT');
    $name = getenv('STALL_DB_NAME');
    $user = getenv('STALL_DB_USER');
    $adminPassword = getenv('STALL_DB_PASSWORD');
    if (!is_string($host) || $host === '') {
        $host = '127.0.0.1';
    }
    if (!is_string($port) || $port === '') {
        $port = '5436';
    }
    if (!is_string($name) || $name === '') {
        $name = 'consign';
    }
    if (!is_string($user) || $user === '') {
        $user = 'stall';
    }
    if (!is_string($adminPassword) || $adminPassword === '') {
        fwrite(STDERR, "STALL_DB_PASSWORD is required\n");
        exit(1);
    }
    if (str_contains($host, ';') || str_contains($name, ';')) {
        fwrite(STDERR, "STALL_DB_HOST or STALL_DB_NAME is invalid\n");
        exit(1);
    }
    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        fwrite(STDERR, "STALL_DB_PORT is invalid\n");
        exit(1);
    }

    return new PDO(
        'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
        $user,
        $adminPassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function ownerEmail(mixed $email): ?string
{
    if (!is_string($email) || trim($email) === '') {
        return null;
    }
    $email = trim($email);
    if (strlen($email) > 200 || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1) {
        fwrite(STDERR, "STALL_OWNER_EMAIL is not an email address\n");
        exit(1);
    }

    return $email;
}
