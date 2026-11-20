<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin\Mfa;

use PerfectConsign\Bootstrap\SignInFailureRetention;
use PerfectConsign\Bootstrap\SignInLocked;
use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Shared\Http\Request;
use PDO;
use Psr\Log\LoggerInterface;

final class AdminMfaFailures
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Request $request,
        private readonly LoggerInterface $logger,
        private readonly AdminAudit $audit,
        private readonly SignInFailureRetention $retention,
    ) {
    }

    public function assertOpen(string $email): void
    {
        if ($this->recentForPair($email) >= 5) {
            $this->locked($email, 'pair');
        }
        if ($this->recentForClient() >= 40) {
            $this->locked($email, 'client');
        }
        if ($this->recentForEmail($email) >= 20) {
            $this->locked($email, 'email');
        }
    }

    public function record(string $adminId, string $email): void
    {
        $this->retention->pruneAdminMfa($email);
        $failed = $this->pdo->prepare(
            'INSERT INTO platform.admin_mfa_failures (email, client_address) VALUES (?, ?)'
        );
        $failed->execute([$email, $this->request->address]);
        $this->audit->record($adminId, $email, 'admin.mfa_refused', 'refused');
        $this->logger->info('platform admin authenticator refused', ['email' => $email]);
    }

    /**
     * @param array<string, string> $detail
     */
    public function replay(string $adminId, string $email, array $detail): void
    {
        $this->audit->record($adminId, $email, 'admin.mfa_refused', 'refused', null, null, $detail);
        $this->logger->info('platform admin authenticator replay refused', ['email' => $email]);
    }

    public function clearEmail(string $email): void
    {
        $clear = $this->pdo->prepare('DELETE FROM platform.admin_mfa_failures WHERE email = ?');
        $clear->execute([$email]);
    }

    private function locked(string $email, string $scope): never
    {
        $this->logger->info('platform admin authenticator locked', ['email' => $email, 'scope' => $scope]);
        throw new SignInLocked('Too many sign-in attempts. Try again in a few minutes.');
    }

    private function recentForPair(string $email): int
    {
        $recent = $this->pdo->prepare(
            "SELECT count(*)
             FROM platform.admin_mfa_failures
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
             FROM platform.admin_mfa_failures
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
             FROM platform.admin_mfa_failures
             WHERE email = ?
               AND failed_at > clock_timestamp() - interval '15 minutes'"
        );
        $recent->execute([$email]);

        return (int) $recent->fetchColumn();
    }
}
