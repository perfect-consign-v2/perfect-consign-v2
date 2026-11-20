<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath((string) $argv[0]) !== realpath(__FILE__)) {
    return;
}

require_once __DIR__ . '/load-env.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\RequestId;

$email = $argv[1] ?? '';
if (!is_string($email)) {
    fwrite(STDERR, "an admin email is required\n");
    exit(1);
}
$email = strtolower(trim($email));
if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1 || strlen($email) > 200) {
    fwrite(STDERR, "an admin email is required\n");
    exit(1);
}

try {
    $pdo = clearAdminMfaPdo();
    $admin = clearAdminMfaFind($pdo, $email);
    $unused = clearAdminMfaUnused($pdo, $admin['id']);
    if ($admin['secret'] === null && $unused === 0) {
        fwrite(STDOUT, "platform admin authenticator already clear\n");
        exit(0);
    }
    $pdo->beginTransaction();
    if ($unused > 0) {
        $burn = $pdo->prepare(
            'UPDATE platform.admin_mfa_recovery_codes
             SET used_at = clock_timestamp()
             WHERE admin_id = ?::uuid AND used_at IS NULL'
        );
        $burn->execute([$admin['id']]);
    }
    if ($admin['secret'] !== null) {
        $clear = $pdo->prepare(
            'UPDATE platform.admins
             SET mfa_secret = NULL, mfa_confirmed_at = NULL, mfa_last_used_step = NULL
             WHERE id = ?::uuid'
        );
        $clear->execute([$admin['id']]);
    }
    $audit = new AdminAudit(
        $pdo,
        new Request('POST', '/clear-admin-mfa', [], [], '', '', ''),
        new RequestId('cli-mfa-clear'),
    );
    $audit->record($admin['id'], $admin['email'], 'admin.mfa_cleared', 'ok');
    $pdo->commit();
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "platform admin authenticator cleared\n");

/**
 * @return array{id: string, email: string, secret: string|null}
 */
function clearAdminMfaFind(PDO $pdo, string $email): array
{
    $statement = $pdo->prepare(
        'SELECT id::text AS id, email, mfa_secret
         FROM platform.admins
         WHERE lower(email) = lower(?)'
    );
    $statement->execute([$email]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('that platform admin was not found');
    }
    $id = $row['id'] ?? null;
    $stored = $row['email'] ?? null;
    $secret = $row['mfa_secret'] ?? null;
    if (!is_string($id) || !is_string($stored)) {
        throw new RuntimeException('that platform admin was not found');
    }
    if ($secret !== null && !is_string($secret)) {
        throw new RuntimeException('the authenticator secret could not be read');
    }

    return ['id' => $id, 'email' => $stored, 'secret' => $secret === '' ? null : $secret];
}

function clearAdminMfaUnused(PDO $pdo, string $adminId): int
{
    $statement = $pdo->prepare(
        'SELECT count(*)
         FROM platform.admin_mfa_recovery_codes
         WHERE admin_id = ?::uuid AND used_at IS NULL'
    );
    $statement->execute([$adminId]);
    $count = $statement->fetchColumn();
    if (is_int($count)) {
        return $count;
    }
    if (!is_string($count) || ctype_digit($count) !== true) {
        throw new RuntimeException('recovery codes could not be counted');
    }

    return (int) $count;
}

function clearAdminMfaPdo(): PDO
{
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
        throw new RuntimeException('STALL_DB_PASSWORD is required');
    }
    if (str_contains($host, ';') || str_contains($name, ';')) {
        throw new RuntimeException('STALL_DB_HOST or STALL_DB_NAME is invalid');
    }
    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('STALL_DB_PORT is invalid');
    }

    return new PDO(
        'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}
