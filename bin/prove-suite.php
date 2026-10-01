<?php

declare(strict_types=1);

require __DIR__ . '/load-env.php';
require __DIR__ . '/provision-tenant.php';
loadStallEnv(dirname(__DIR__) . '/.env');

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
$directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql';
$provisioner = new TenantProvisioner($pdo, $directory);
$slug = 'proofsuite';
removeProofTenant($pdo, $slug);
$schema = $provisioner->provision($slug, 'Proof suite');
$role = $provisioner->roleName($slug, $schema);
$failed = 0;
try {
    $skip = [
        'prove_app.sql' => true,
        'prove_tenant.sql' => true,
        'prove_tenant_race_hold.sql' => true,
        'prove_tenant_race_wait.sql' => true,
    ];
    $files = glob($directory . DIRECTORY_SEPARATOR . 'prove_*.sql');
    if ($files === false) {
        fwrite(STDERR, "could not list proofs\n");
        exit(1);
    }
    sort($files);
    foreach ($files as $path) {
        $file = basename($path);
        if (isset($skip[$file]) || str_starts_with($file, 'prove_race_')) {
            continue;
        }
        $sql = file_get_contents($path);
        if ($sql === false) {
            fwrite(STDERR, "could not read $file\n");
            exit(1);
        }
        try {
            $pdo->exec('RESET ROLE');
            $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
            $pdo->exec($provisioner->rewrite($sql, $schema, $role));
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
                fwrite(STDERR, "left open $file\n");
                $failed++;
                continue;
            }
            fwrite(STDOUT, "ok $file\n");
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fwrite(STDERR, "FAIL $file\n" . $error->getMessage() . "\n");
            $failed++;
        }
    }
} finally {
    removeProofTenant($pdo, $slug);
}
if ($failed > 0) {
    fwrite(STDERR, $failed . " proofs failed\n");
    exit(1);
}

function removeProofTenant(PDO $pdo, string $slug): void
{
    if ($slug !== 'proofsuite') {
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
    $pdo->exec('DELETE FROM platform.tenants WHERE slug = ' . $pdo->quote($slug));
    $pdo->exec('DROP SCHEMA ' . $schema . ' CASCADE');
    $pdo->exec('REVOKE ' . $role . ' FROM stall_app');
    $pdo->exec('DROP OWNED BY ' . $role);
    $pdo->exec('DROP ROLE ' . $role);
}
