<?php

declare(strict_types=1);

/**
 * List probe bans, or lift one from the server shell when the admin page cannot be reached.
 *
 *   php bin/lift-probe-ban.php
 *   php bin/lift-probe-ban.php 203.0.113.7 admin@example.com
 *
 * The admin email names who lifted it in the admin audit, the same as the Lift ban button.
 */

if (PHP_SAPI !== 'cli' || !isset($argv[0]) || realpath((string) $argv[0]) !== realpath(__FILE__)) {
    return;
}

require_once __DIR__ . '/load-env.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Feature\Backup\BackupConnection;
use PerfectConsign\Shared\Http\ProbeBanStore;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\RequestId;

$address = $argv[1] ?? null;
$email = $argv[2] ?? null;

try {
    $pdo = BackupConnection::admin()->pdo();
    $store = new ProbeBanStore($pdo);
    if ($address === null) {
        liftProbeBanList($store);
        exit(0);
    }
    if (!is_string($address) || preg_match('/\A[A-Za-z0-9:.]{1,64}\z/', $address) !== 1) {
        throw new RuntimeException('give the banned address, then the admin email');
    }
    if (!is_string($email)) {
        throw new RuntimeException('give the banned address, then the admin email');
    }
    $admin = liftProbeBanAdmin($pdo, $email);
    $pdo->beginTransaction();
    if (!$store->lift($address)) {
        $pdo->rollBack();
        fwrite(STDOUT, $address . " is not banned\n");
        exit(0);
    }
    $audit = new AdminAudit(
        $pdo,
        new Request('POST', '/lift-probe-ban', [], [], '', '', ''),
        new RequestId('cli-probe-ban-lift'),
    );
    $audit->record($admin['id'], $admin['email'], 'probe_ban_lift', 'ok', null, null, ['address' => $address]);
    $pdo->commit();
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, 'lifted the ban for ' . $address . "\n");

function liftProbeBanList(ProbeBanStore $store): void
{
    $bans = $store->listBans();
    if ($bans === []) {
        fwrite(STDOUT, "no addresses are banned\n");

        return;
    }
    foreach ($bans as $ban) {
        fwrite(STDOUT, implode('  ', [
            $ban['address'],
            $ban['active'] ? 'until ' . $ban['banned_until'] : 'ended',
            'times ' . (string) $ban['strikes'],
            $ban['reason'],
            $ban['path'],
        ]) . "\n");
    }
}

/**
 * @return array{id: string, email: string}
 */
function liftProbeBanAdmin(PDO $pdo, string $email): array
{
    $email = strtolower(trim($email));
    if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1 || strlen($email) > 200) {
        throw new RuntimeException('give the banned address, then the admin email');
    }
    $statement = $pdo->prepare(
        'SELECT id::text AS id, email FROM platform.admins WHERE lower(email) = lower(?)'
    );
    $statement->execute([$email]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    $id = is_array($row) ? ($row['id'] ?? null) : null;
    $stored = is_array($row) ? ($row['email'] ?? null) : null;
    if (!is_string($id) || !is_string($stored)) {
        throw new RuntimeException('that platform admin was not found');
    }

    return ['id' => $id, 'email' => $stored];
}
