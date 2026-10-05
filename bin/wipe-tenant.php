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
    dropBackupTenantKey($pdo);
    $pdo->exec(
        'DELETE FROM platform.owners WHERE tenant_id = (SELECT id FROM platform.tenants WHERE slug = ' . $quotedSlug . ')'
    );
    $pdo->exec('DELETE FROM platform.tenants WHERE slug = ' . $quotedSlug);
    $pdo->exec('DROP SCHEMA ' . $schema . ' CASCADE');
    $present = $pdo->prepare('SELECT 1 FROM pg_roles WHERE rolname = ?');
    $present->execute([$role]);
    if ($present->fetchColumn() === false) {
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
    $pdo->exec('DROP OWNED BY ' . $role);
    $pdo->exec('DROP ROLE ' . $role);
}

function dropBackupTenantKey(PDO $pdo): void
{
    $found = $pdo->query(
        "SELECT con.conname
         FROM pg_constraint AS con
         WHERE con.conrelid = 'platform.owner_backups'::regclass
           AND con.contype = 'f'
           AND con.confrelid = 'platform.tenants'::regclass"
    );
    if ($found === false) {
        throw new RuntimeException('could not read owner backup constraints');
    }
    $constraint = $found->fetchColumn();
    if (!is_string($constraint) || preg_match('/^[a-z_][a-z0-9_]*$/', $constraint) !== 1) {
        return;
    }
    $pdo->exec('ALTER TABLE platform.owner_backups DROP CONSTRAINT ' . $constraint);
}
