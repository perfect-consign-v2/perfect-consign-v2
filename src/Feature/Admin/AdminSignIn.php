<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Bootstrap\SignInFailureRetention;
use PerfectConsign\Bootstrap\SignInLocked;
use PerfectConsign\Shared\Http\Request;
use PDO;
use Psr\Log\LoggerInterface;

final class AdminSignIn
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Request $request,
        private readonly LoggerInterface $logger,
        private readonly AdminSession $session,
        private readonly AdminAudit $audit,
        private readonly SignInFailureRetention $failures,
    ) {
    }

    public function attempt(string $email, string $password): bool
    {
        $email = strtolower(trim($email));
        if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1 || strlen($email) > 200) {
            $this->logger->info('platform admin sign-in refused');

            return false;
        }
        $this->refuseLocked($email);
        $row = $this->lookup($email);
        if ($row === null || !password_verify($password, $row['hash'])) {
            $this->failed($email, $row);
            $this->logger->info('platform admin sign-in refused');

            return false;
        }
        $clear = $this->pdo->prepare(
            'DELETE FROM platform.admin_sign_in_failures WHERE email = ?'
        );
        $clear->execute([$email]);
        $this->session->open($row['id'], $email, $row['version']);
        $this->audit->record($row['id'], $email, 'admin.sign_in', 'ok');

        return true;
    }

    /**
     * @return array{id: string, hash: string, version: string}|null
     */
    private function lookup(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, password_hash, credentials_version
             FROM platform.admins
             WHERE lower(email) = lower(?)'
        );
        $statement->execute([$email]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $id = $row['id'] ?? null;
        $hash = $row['password_hash'] ?? null;
        $version = $row['credentials_version'] ?? null;
        if (is_int($version)) {
            $version = (string) $version;
        }
        if (!is_string($id) || !is_string($hash) || !is_string($version) || ctype_digit($version) !== true) {
            return null;
        }

        return ['id' => $id, 'hash' => $hash, 'version' => $version];
    }

    /**
     * @param array{id: string, hash: string, version: string}|null $row
     */
    private function failed(string $email, ?array $row): void
    {
        $this->failures->pruneAdmin($email);
        $failed = $this->pdo->prepare(
            'INSERT INTO platform.admin_sign_in_failures (email, client_address) VALUES (?, ?)'
        );
        $failed->execute([$email, $this->request->address]);
        if ($row === null) {
            return;
        }
        $this->audit->record(
            $row['id'],
            $email,
            'admin.sign_in_refused',
            'refused',
        );
    }

    private function refuseLocked(string $email): void
    {
        $recent = $this->pdo->prepare(
            "SELECT count(*)
             FROM platform.admin_sign_in_failures
             WHERE email = ?
               AND client_address = ?
               AND failed_at > clock_timestamp() - interval '15 minutes'"
        );
        $recent->execute([$email, $this->request->address]);
        if ((int) $recent->fetchColumn() >= 5) {
            $this->logger->info('platform admin sign-in locked', ['email' => $email]);
            throw new SignInLocked('Too many sign-in attempts. Try again in a few minutes.');
        }
    }
}
