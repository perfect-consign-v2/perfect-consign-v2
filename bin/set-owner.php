<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

require_once __DIR__ . '/load-env.php';
loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

$slug = $argv[1] ?? '';
$username = $argv[2] ?? '';
$password = getenv('STALL_OWNER_PASSWORD');
if (!is_string($password) || $password === '') {
    fwrite(STDERR, "STALL_OWNER_PASSWORD is required\n");
    exit(1);
}
$email = ownerEmail(getenv('STALL_OWNER_EMAIL'));
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
    $adminPassword = 'stall';
}
if (!is_string($slug) || $slug === '' || !is_string($username) || $username === '') {
    fwrite(STDERR, "usage: php bin/set-owner.php <slug> <username>\n");
    exit(1);
}

$pdo = new PDO(
    'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
    $user,
    $adminPassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$current = $pdo->prepare(
    'SELECT o.id, o.username, o.password_hash, o.email
     FROM platform.owners o
     INNER JOIN platform.tenants t ON t.id = o.tenant_id
     WHERE t.slug = ?'
);
$current->execute([$slug]);
$existing = $current->fetch(PDO::FETCH_ASSOC);
if (is_array($existing) && passwordMatches($password, $existing['password_hash'] ?? null)) {
    $sameUser = is_string($existing['username'] ?? null) && $existing['username'] === $username;
    $sameEmail = ownerEmailsMatch($email, $existing['email'] ?? null);
    if ($sameUser && $sameEmail) {
        fwrite(STDOUT, "owner unchanged\n");
        exit(0);
    }
    $touch = $pdo->prepare(
        'UPDATE platform.owners
         SET username = ?,
             email = COALESCE(?, email),
             email_verified_at = COALESCE(email_verified_at, clock_timestamp())
         WHERE id = ?::uuid'
    );
    try {
        $touch->execute([$username, $email, $existing['id']]);
    } catch (PDOException $error) {
        refuseDuplicateEmail($error);
        throw $error;
    }
    fwrite(STDOUT, "owner profile saved\n");
    exit(0);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
if ($hash === false) {
    fwrite(STDERR, "the password hash could not be created\n");
    exit(1);
}
$statement = $pdo->prepare(
    'INSERT INTO platform.owners (id, tenant_id, username, password_hash, email, email_verified_at)
     SELECT uuidv7(), id, ?, ?, ?, clock_timestamp()
     FROM platform.tenants
     WHERE slug = ?
     ON CONFLICT (tenant_id) DO UPDATE
        SET username = EXCLUDED.username,
            password_hash = EXCLUDED.password_hash,
            email = COALESCE(EXCLUDED.email, platform.owners.email),
            email_verified_at = COALESCE(platform.owners.email_verified_at, clock_timestamp()),
            credentials_version = platform.owners.credentials_version + 1'
);
try {
    $statement->execute([$username, $hash, $email, $slug]);
} catch (PDOException $error) {
    refuseDuplicateEmail($error);
    throw $error;
}
if ($statement->rowCount() < 1) {
    fwrite(STDERR, "that mall was not found\n");
    exit(1);
}
fwrite(STDOUT, "owner saved\n");

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

function passwordMatches(string $password, mixed $hash): bool
{
    return is_string($hash) && $hash !== '' && password_verify($password, $hash);
}

function ownerEmailsMatch(?string $wanted, mixed $stored): bool
{
    if ($wanted === null) {
        return true;
    }

    return is_string($stored) && $stored === $wanted;
}

function refuseDuplicateEmail(PDOException $error): void
{
    $state = $error->errorInfo[0] ?? '';
    if ($state === '23505') {
        fwrite(STDERR, "that email is already used on another sign-in\n");
        exit(1);
    }
}
