<?php

declare(strict_types=1);

require_once __DIR__ . '/load-env.php';
require_once __DIR__ . '/provision-tenant.php';

loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

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

$slug = 'race';
wipeRaceTenant($pdo, $slug);
$provisioner = new TenantProvisioner($pdo, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql');
$schema = $provisioner->provision($slug, 'Race');
fwrite(STDOUT, $schema . "\n");

function wipeRaceTenant(PDO $pdo, string $slug): void
{
    if ($slug !== 'race') {
        throw new RuntimeException('refusing to remove ' . $slug);
    }
    $statement = $pdo->prepare('SELECT schema_name FROM platform.tenants WHERE slug = ?');
    $statement->execute([$slug]);
    $schema = $statement->fetchColumn();
    if (!is_string($schema) || $schema === '') {
        return;
    }
    if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
        throw new RuntimeException('refusing to remove ' . $schema);
    }
    $role = 'poster_' . substr($schema, strlen('tenant_'));
    $quotedSlug = $pdo->quote($slug);
    $pdo->exec(
        'DELETE FROM platform.owner_backups WHERE tenant_id = (SELECT id FROM platform.tenants WHERE slug = ' . $quotedSlug . ')'
    );
    $pdo->exec(
        'DELETE FROM platform.owners WHERE tenant_id = (SELECT id FROM platform.tenants WHERE slug = ' . $quotedSlug . ')'
    );
    $pdo->exec('DELETE FROM platform.tenants WHERE slug = ' . $quotedSlug);
    $pdo->exec('DROP SCHEMA ' . $schema . ' CASCADE');
    $member = $pdo->prepare(
        'SELECT 1
         FROM pg_auth_members AS membership
         JOIN pg_roles AS granted ON granted.oid = membership.roleid
         JOIN pg_roles AS login ON login.oid = membership.member
         WHERE granted.rolname = ? AND login.rolname = \'stall_app\''
    );
    $member->execute([$role]);
    if ($member->fetchColumn() !== false) {
        $pdo->exec('REVOKE ' . $role . ' FROM stall_app');
    }
    $pdo->exec('DROP OWNED BY ' . $role);
    $pdo->exec('DROP ROLE ' . $role);
}
