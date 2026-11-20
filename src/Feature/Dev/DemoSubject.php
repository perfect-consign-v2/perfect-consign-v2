<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Dev;

use PDO;
use PerfectApp\Session\Session;
use RuntimeException;

final class DemoSubject
{
    public static function stamp(PDO $pdo, string $schema, Session $session): void
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new RuntimeException('the demo owner is missing');
        }
        $statement = $pdo->prepare(
            'SELECT owners.id::text AS id, owners.username
             FROM platform.owners AS owners
             JOIN platform.tenants AS tenants ON tenants.id = owners.tenant_id
             WHERE tenants.schema_name = ?
             ORDER BY owners.created_at
             LIMIT 1'
        );
        $statement->execute([$schema]);
        $row = $statement->fetch();
        $id = is_array($row) ? ($row['id'] ?? null) : null;
        $username = is_array($row) ? ($row['username'] ?? null) : null;
        if (!is_string($id) || preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new RuntimeException('the demo owner is missing');
        }
        if (!is_string($username) || preg_match('/^[a-z][a-z0-9-]{0,62}$/', $username) !== 1) {
            throw new RuntimeException('the demo owner is missing');
        }
        $now = (string) time();
        $session->set('owner_name', $username);
        $session->set('actor_username', $username);
        $session->set('actor_role', 'owner');
        $session->set('actor_account', $id);
        $session->set('signed_in_at', $now);
        $session->set('last_seen_at', $now);
    }
}
