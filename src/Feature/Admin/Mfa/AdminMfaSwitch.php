<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin\Mfa;

use PerfectConsign\Bootstrap\SignInFailureRetention;
use PerfectConsign\Bootstrap\SignInLocked;
use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Feature\Signup\PlatformSettings;
use PerfectConsign\Shared\Http\Request;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Turns the platform authenticator requirement on or off.
 * The sealed phone secret stays. Removing it is still the owner CLI.
 */
final class AdminMfaSwitch
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PlatformSettings $settings,
        private readonly AdminMfaStore $store,
        private readonly AdminMfaFailures $failures,
        private readonly AdminAudit $audit,
        private readonly SignInFailureRetention $passwords,
        private readonly Request $request,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function turnOn(string $adminId, string $email, string $password): void
    {
        $this->assertPassword($adminId, $email, $password);
        $this->pdo->beginTransaction();
        try {
            $this->settings->setAdminMfaEnabled(true);
            $this->audit->record($adminId, $email, 'admin.mfa_enabled', 'ok');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        $this->logger->info('platform admin authenticator switched', [
            'email' => $email,
            'enabled' => true,
        ]);
    }

    public function turnOff(string $adminId, string $email, string $password, string $code, int $now): void
    {
        $this->assertPassword($adminId, $email, $password);
        if (!$this->store->hasSecret($adminId)) {
            throw new \InvalidArgumentException('Set up an authenticator before turning it off.');
        }
        if ($code === '') {
            throw new \InvalidArgumentException('Enter the authenticator code to turn it off.');
        }
        $this->failures->assertOpen($email);
        $step = $this->step($adminId, $email, $code, $now);
        if ($step === null) {
            $this->failures->record($adminId, $email);
            throw new \InvalidArgumentException('That code is wrong.');
        }
        $advanced = false;
        $this->pdo->beginTransaction();
        try {
            $advanced = $this->store->advance($adminId, $step);
            if (!$advanced) {
                $this->pdo->rollBack();
            } else {
                $this->settings->setAdminMfaEnabled(false);
                $this->audit->record($adminId, $email, 'admin.mfa_disabled', 'ok');
                $this->pdo->commit();
            }
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$advanced) {
            $this->failures->replay($adminId, $email, ['reason' => 'replay']);
            throw new \InvalidArgumentException('That code was already used.');
        }
        $this->logger->info('platform admin authenticator switched', [
            'email' => $email,
            'enabled' => false,
        ]);
    }

    private function assertPassword(string $adminId, string $email, string $password): void
    {
        $mailbox = strtolower(trim($email));
        $this->refuseLocked($mailbox);
        $statement = $this->pdo->prepare(
            'SELECT password_hash FROM platform.admins WHERE id = ?::uuid'
        );
        $statement->execute([$adminId]);
        $hash = $statement->fetchColumn();
        if (!is_string($hash) || $hash === '' || !password_verify($password, $hash)) {
            $this->recordPasswordFailure($mailbox);
            $this->logger->info('platform admin authenticator switch refused', ['email' => $email]);
            throw new \InvalidArgumentException('That password is wrong.');
        }
        $clear = $this->pdo->prepare('DELETE FROM platform.admin_sign_in_failures WHERE email = ?');
        $clear->execute([$mailbox]);
    }

    private function recordPasswordFailure(string $email): void
    {
        $this->passwords->pruneAdmin($email);
        $failed = $this->pdo->prepare(
            'INSERT INTO platform.admin_sign_in_failures (email, client_address) VALUES (?, ?)'
        );
        $failed->execute([$email, $this->request->address]);
    }

    private function refuseLocked(string $email): void
    {
        if ($this->recentForPair($email) >= 5 || $this->recentForClient() >= 40 || $this->recentForEmail($email) >= 20) {
            $this->logger->info('platform admin authenticator switch locked', ['email' => $email]);
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

    private function step(string $adminId, string $email, string $code, int $now): ?int
    {
        try {
            return $this->store->acceptedStep($adminId, $code, $now);
        } catch (RuntimeException $error) {
            $this->logger->error('platform admin authenticator secret could not be opened', [
                'email' => $email,
                'error' => $error->getMessage(),
            ]);
            throw new \InvalidArgumentException('The authenticator could not be read.');
        }
    }
}
