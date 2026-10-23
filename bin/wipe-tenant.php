<?php

declare(strict_types=1);

function wipeTenant(PDO $pdo, string $slug, string $expectedSlug): void
{
    if ($slug !== $expectedSlug) {
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
    $role = $slug === 'mall' ? 'stall_poster' : 'poster_' . substr($schema, strlen('tenant_'));
    $quotedSlug = $pdo->quote($slug);
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        keepPasswordResetKey($pdo, $quotedSlug);
        $pdo->exec(
            'DELETE FROM platform.owner_sign_in_failures WHERE tenant_id = (SELECT id FROM platform.tenants WHERE slug = ' . $quotedSlug . ')'
        );
        $pdo->exec(
            'DELETE FROM platform.password_reset_requests WHERE tenant_id = (SELECT id FROM platform.tenants WHERE slug = ' . $quotedSlug . ')'
        );
        $pdo->exec(
            'DELETE FROM platform.owners WHERE tenant_id = (SELECT id FROM platform.tenants WHERE slug = ' . $quotedSlug . ')'
        );
        $pdo->exec('DELETE FROM platform.tenants WHERE slug = ' . $quotedSlug);
        $pdo->exec('DROP SCHEMA ' . $schema . ' CASCADE');
        $present = $pdo->prepare('SELECT 1 FROM pg_roles WHERE rolname = ?');
        $present->execute([$role]);
        if ($present->fetchColumn() === false) {
            if ($own) {
                $pdo->commit();
            }

            return;
        }
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
        $pdo->exec('GRANT ' . $role . ' TO CURRENT_USER');
        $pdo->exec('DROP OWNED BY ' . $role);
        $pdo->exec('DROP ROLE ' . $role);
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function keepPasswordResetKey(PDO $pdo, string $quotedSlug): void
{
    $present = $pdo->query("SELECT to_regclass('platform.password_resets')");
    if ($present === false || $present->fetchColumn() === null) {
        return;
    }
    $constraint = $pdo->query(
        "SELECT con.conname
         FROM pg_constraint AS con
         WHERE con.conrelid = 'platform.password_resets'::regclass
           AND con.contype = 'f'
           AND con.confrelid = 'platform.tenants'::regclass"
    );
    if ($constraint === false) {
        throw new RuntimeException('could not read password reset constraints');
    }
    $name = $constraint->fetchColumn();
    if (!is_string($name) || $name === '') {
        $orphans = $pdo->query(
            'SELECT 1
             FROM platform.password_resets AS reset
             WHERE NOT EXISTS (
                 SELECT 1 FROM platform.tenants AS tenant WHERE tenant.id = reset.tenant_id
             )
             LIMIT 1'
        );
        if ($orphans === false) {
            throw new RuntimeException('could not read password resets');
        }
        if ($orphans->fetchColumn() !== false) {
            throw new RuntimeException('the password reset foreign key is missing');
        }
        $pdo->exec(
            'ALTER TABLE platform.password_resets
             ADD CONSTRAINT password_resets_tenant_id_fkey
             FOREIGN KEY (tenant_id) REFERENCES platform.tenants (id)'
        );
    }
    $found = $pdo->query(
        'SELECT 1
         FROM platform.password_resets
         WHERE tenant_id = (SELECT id FROM platform.tenants WHERE slug = ' . $quotedSlug . ')
         LIMIT 1'
    );
    if ($found === false) {
        throw new RuntimeException('could not read password resets');
    }
    if ($found->fetchColumn() !== false) {
        throw new RuntimeException('a password reset still names this mall');
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === realpath(__FILE__)) {
    require_once __DIR__ . '/load-env.php';
    loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');
    $slug = $argv[1] ?? '';
    if (!is_string($slug) || $slug === '' || isset($argv[2])) {
        fwrite(STDERR, "usage: php bin/wipe-tenant.php <slug>\n");
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
    try {
        wipeTenant($pdo, $slug, $slug);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
    fwrite(STDOUT, $slug . "\n");
}
