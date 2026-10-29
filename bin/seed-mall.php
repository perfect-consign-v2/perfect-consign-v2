<?php

declare(strict_types=1);

require_once __DIR__ . '/load-env.php';
require_once __DIR__ . '/provision-tenant.php';
require_once __DIR__ . '/wipe-tenant.php';
require_once __DIR__ . '/MallDemo.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

$ownerPassword = getenv('STALL_OWNER_PASSWORD');
if (!is_string($ownerPassword) || $ownerPassword === '') {
    fwrite(STDERR, "STALL_OWNER_PASSWORD is required\n");
    exit(1);
}

$host = getenv('STALL_DB_HOST');
$port = getenv('STALL_DB_PORT');
$name = getenv('STALL_DB_NAME');
$user = getenv('STALL_DB_USER');
$password = getenv('STALL_DB_PASSWORD');
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
if (!is_string($password) || $password === '') {
    $password = 'stall';
}

$pdo = new PDO(
    'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

fwrite(STDOUT, "removing mall\n");
wipeTenant($pdo, 'mall', 'mall');

$provisioner = new TenantProvisioner($pdo, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql');
$schema = $provisioner->provision('mall', 'Mall');
fwrite(STDOUT, $schema . "\n");

try {
    (new MallDemo($pdo, $schema))->load();
} catch (Throwable $error) {
    $previous = $error->getPrevious();
    $detail = $previous instanceof Throwable ? $previous->getMessage() : '';
    fwrite(STDERR, $error->getMessage() . "\n" . $detail . "\n");
    exit(1);
}

$hash = password_hash($ownerPassword, PASSWORD_DEFAULT);
if ($hash === false) {
    fwrite(STDERR, "the password hash could not be created\n");
    exit(1);
}
$ownerEmail = getenv('STALL_OWNER_EMAIL');
if (!is_string($ownerEmail) || trim($ownerEmail) === '') {
    $ownerEmail = null;
} else {
    $ownerEmail = strtolower(trim($ownerEmail));
}
$owner = $pdo->prepare(
    'INSERT INTO platform.owners (id, tenant_id, username, password_hash, email, email_verified_at)
     SELECT uuidv7(), id, ?, ?, ?, CASE WHEN ?::text IS NULL THEN NULL ELSE clock_timestamp() END
     FROM platform.tenants
     WHERE slug = ?
     ON CONFLICT (tenant_id) DO UPDATE
        SET username = EXCLUDED.username,
            password_hash = EXCLUDED.password_hash,
            email = COALESCE(EXCLUDED.email, platform.owners.email),
            email_verified_at = COALESCE(platform.owners.email_verified_at, EXCLUDED.email_verified_at)'
);
try {
    $owner->execute(['owner', $hash, $ownerEmail, $ownerEmail, 'mall']);
} catch (PDOException $error) {
    $state = $error->errorInfo[0] ?? '';
    if ($state === '23505') {
        fwrite(STDERR, "that email is already used on another sign-in\n");
        exit(1);
    }
    throw $error;
}
if ($ownerEmail !== null) {
    fwrite(STDOUT, "sign in at / with {$ownerEmail}. The password is STALL_OWNER_PASSWORD.\n");
} else {
    fwrite(STDOUT, "set STALL_OWNER_EMAIL then re-run set-owner; sign-in is email-only at /\n");
}

try {
    $shop = (new PerfectConsign\Feature\Dev\ShopTenantReset())->load();
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
fwrite(STDOUT, $shop . "\n");
fwrite(STDOUT, "sign in at /shop as owner. The password is STALL_OWNER_PASSWORD.\n");
