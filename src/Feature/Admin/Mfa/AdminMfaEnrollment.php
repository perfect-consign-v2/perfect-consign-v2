<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin\Mfa;

use Psr\Log\LoggerInterface;

final class AdminMfaEnrollment
{
    public function __construct(
        private readonly AdminMfaPending $pending,
        private readonly AdminMfaStore $store,
        private readonly AdminMfaFailures $failures,
        private readonly AdminMfaLogin $login,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function confirm(string $code, int $now): AdminMfaAttempt
    {
        $pending = $this->pending->enrollment($now);
        if ($pending === null) {
            return AdminMfaAttempt::invalid('That sign-in expired. Sign in again.');
        }
        $this->failures->assertOpen($pending['email']);
        $raw = Base32::decode($pending['secret']);
        if ($raw === null) {
            $this->logger->error('platform admin enrollment secret is corrupt', ['email' => $pending['email']]);

            return AdminMfaAttempt::invalid('That sign-in expired. Sign in again.');
        }
        $step = Totp::verify($raw, $code, $now);
        if ($step === null) {
            $this->failures->record($pending['id'], $pending['email']);

            return AdminMfaAttempt::invalid('That code is wrong.');
        }

        return $this->save($pending, $raw, $step);
    }

    public function accept(int $now): AdminMfaAttempt
    {
        $pending = $this->pending->enrollment($now);
        $codes = $this->pending->codes();
        if ($pending === null || $codes === null) {
            return AdminMfaAttempt::invalid('That sign-in expired. Sign in again.');
        }
        $this->login->open($pending['id'], $pending['email']);

        return AdminMfaAttempt::success();
    }

    /**
     * @param array{id: string, email: string, secret: string, grouped: string} $pending
     */
    private function save(array $pending, string $raw, int $step): AdminMfaAttempt
    {
        $codes = RecoveryCode::batch();
        $hashes = [];
        foreach ($codes as $code) {
            $hashes[] = RecoveryCode::hash(RecoveryCode::normalize($code));
        }
        $sealed = AdminMfaSeal::seal($raw);
        if (!$this->store->enroll($pending['id'], $pending['email'], $sealed, $step, $hashes)) {
            return AdminMfaAttempt::invalid('That authenticator was already saved. Sign in again.');
        }
        $this->pending->rememberCodes($codes);
        $this->failures->clearEmail($pending['email']);

        return AdminMfaAttempt::success();
    }
}
