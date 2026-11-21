<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Bootstrap\SignInFailureRetention;
use PerfectConsign\Bootstrap\SignInLocked;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaLogin;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaPending;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaStore;
use PerfectConsign\Feature\Signup\PlatformSettings;
use PerfectConsign\Shared\Http\Request;
use PDO;
use Psr\Log\LoggerInterface;

final class AdminSignIn
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Request $request,
        private readonly LoggerInterface $logger,
        private readonly AdminAudit $audit,
        private readonly SignInFailureRetention $failures,
        private readonly AdminMfaStore $authenticators,
        private readonly AdminMfaPending $pending,
        private readonly PlatformSettings $settings,
        private readonly AdminMfaLogin $login,
    ) {
    }

    public function attempt(string $email, string $password): AdminSignInResult
    {
        $email = strtolower(trim($email));
        if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1 || strlen($email) > 200) {
            $this->logger->info('platform admin sign-in refused');

            return AdminSignInResult::refused();
        }
        $this->refuseLocked($email);
        $row = $this->lookup($email);
        if ($row === null || !password_verify($password, $row['hash'])) {
            $this->failed($email, $row);
            $this->logger->info('platform admin sign-in refused');

            return AdminSignInResult::refused();
        }
        $clear = $this->pdo->prepare(
            'DELETE FROM platform.admin_sign_in_failures WHERE email = ?'
        );
        $clear->execute([$email]);
        if (!$this->settings->adminMfaEnabled()) {
            $this->login->open($row['id'], $email);

            return AdminSignInResult::signedIn();
        }
        if ($this->authenticators->hasSecret($row['id'])) {
            $this->pending->beginChallenge($row['id'], $email);

            return AdminSignInResult::challenge();
        }
        $this->pending->beginEnrollment($row['id'], $email);

        return AdminSignInResult::enroll();
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
        if ($this->recentForPair($email) >= 5) {
            $this->logger->info('platform admin sign-in locked', ['email' => $email, 'scope' => 'pair']);
            throw new SignInLocked('Too many sign-in attempts. Try again in a few minutes.');
        }
        if ($this->recentForClient() >= 40) {
            $this->logger->info('platform admin sign-in locked', ['email' => $email, 'scope' => 'client']);
            throw new SignInLocked('Too many sign-in attempts. Try again in a few minutes.');
        }
        if ($this->recentForEmail($email) >= 20) {
            $this->logger->info('platform admin sign-in locked', ['email' => $email, 'scope' => 'email']);
            throw new SignInLocked('Too many sign-in attempts. Try again in a few minutes.');
        }
    }

    private function recentForPair(string $email): int
    {
        $recent = $this->pdo->prepare(
            "SELECT count(*)
             FROM platform.admin_sign_in_failures
             WHERE email = ?
               AND client_address = ?
               AND failed_at > clock_timestamp() - interval '15 minutes'"
        );
        $recent->execute([$email, $this->request->address]);

        return (int) $recent->fetchColumn();
    }

    private function recentForClient(): int
    {
        $recent = $this->pdo->prepare(
            "SELECT count(*)
             FROM platform.admin_sign_in_failures
             WHERE client_address = ?
               AND failed_at > clock_timestamp() - interval '15 minutes'"
        );
        $recent->execute([$this->request->address]);

        return (int) $recent->fetchColumn();
    }

    private function recentForEmail(string $email): int
    {
        $recent = $this->pdo->prepare(
            "SELECT count(*)
             FROM platform.admin_sign_in_failures
             WHERE email = ?
               AND failed_at > clock_timestamp() - interval '15 minutes'"
        );
        $recent->execute([$email]);

        return (int) $recent->fetchColumn();
    }
}
