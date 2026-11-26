<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Bootstrap\SignInGate;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaLogin;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaPending;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaStore;
use PerfectConsign\Feature\Signup\PlatformSettings;
use PDO;
use Psr\Log\LoggerInterface;

final class AdminSignIn
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger,
        private readonly AdminAudit $audit,
        private readonly SignInGate $gate,
        private readonly AdminMfaStore $authenticators,
        private readonly AdminMfaPending $pending,
        private readonly PlatformSettings $settings,
        private readonly AdminMfaLogin $login,
    ) {
    }

    public function attempt(string $email, string $password): AdminSignInResult
    {
        $email = strtolower(trim($email));
        $row = null;
        if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) === 1 && strlen($email) <= 200) {
            $row = $this->checked($email, $password);
        }
        if ($row === null) {
            $this->logger->info('platform admin sign-in refused');

            return AdminSignInResult::refused();
        }

        return $this->open($row['id'], $email);
    }

    /**
     * The admin row when the gate lets the password in. A wrong password for a
     * known admin is audited.
     *
     * @return array{id: string, hash: string, version: string}|null
     */
    private function checked(string $email, string $password): ?array
    {
        $row = null;
        $right = $this->gate->attemptAdmin($email, $password, function () use ($email, &$row): ?string {
            $row = $this->lookup($email);

            return $row['hash'] ?? null;
        });
        if ($row !== null && !$right) {
            $this->audit->record($row['id'], $email, 'admin.sign_in_refused', 'refused');

            return null;
        }

        return $right ? $row : null;
    }

    private function open(string $adminId, string $email): AdminSignInResult
    {
        if (!$this->settings->adminMfaEnabled()) {
            $this->login->open($adminId, $email);

            return AdminSignInResult::signedIn();
        }
        if ($this->authenticators->hasSecret($adminId)) {
            $this->pending->beginChallenge($adminId, $email);

            return AdminSignInResult::challenge();
        }
        $this->pending->beginEnrollment($adminId, $email);

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
}
