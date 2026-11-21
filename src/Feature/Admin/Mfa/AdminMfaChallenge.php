<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin\Mfa;

use Psr\Log\LoggerInterface;
use RuntimeException;

final class AdminMfaChallenge
{
    public function __construct(
        private readonly AdminMfaPending $pending,
        private readonly AdminMfaStore $store,
        private readonly AdminMfaFailures $failures,
        private readonly AdminMfaLogin $login,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function submit(string $code, string $recovery, int $now): AdminMfaAttempt
    {
        $pending = $this->pending->challenge($now);
        if ($pending === null) {
            return AdminMfaAttempt::invalid('That sign-in expired. Sign in again.');
        }
        if (($code !== '' && $recovery !== '') || ($code === '' && $recovery === '')) {
            return AdminMfaAttempt::invalid('Enter a code or a recovery code.');
        }
        $this->failures->assertOpen($pending['email']);
        if ($recovery !== '') {
            return $this->recovery($pending, $recovery);
        }

        return $this->totp($pending, $code, $now);
    }

    /**
     * @param array{id: string, email: string} $pending
     */
    private function totp(array $pending, string $code, int $now): AdminMfaAttempt
    {
        try {
            $step = $this->store->acceptedStep($pending['id'], $code, $now);
        } catch (RuntimeException $error) {
            $this->logger->error('platform admin authenticator secret could not be opened', [
                'email' => $pending['email'],
                'error' => $error->getMessage(),
            ]);

            return AdminMfaAttempt::invalid('The authenticator could not be read.');
        }
        if ($step === null) {
            if (!$this->store->hasSecret($pending['id'])) {
                return AdminMfaAttempt::invalid('That sign-in expired. Sign in again.');
            }
            $this->failures->record($pending['id'], $pending['email']);

            return AdminMfaAttempt::invalid('That code is wrong.');
        }
        if (!$this->store->advance($pending['id'], $step)) {
            $this->failures->replay($pending['id'], $pending['email'], ['reason' => 'replay']);

            return AdminMfaAttempt::invalid('That code was already used.');
        }
        $this->login->open($pending['id'], $pending['email']);

        return AdminMfaAttempt::success();
    }

    /**
     * @param array{id: string, email: string} $pending
     */
    private function recovery(array $pending, string $recovery): AdminMfaAttempt
    {
        $hash = RecoveryCode::hash(RecoveryCode::normalize($recovery));
        if ($hash === '' || !$this->store->burn($pending['id'], $hash)) {
            $this->failures->record($pending['id'], $pending['email']);

            return AdminMfaAttempt::invalid('That recovery code is wrong.');
        }
        $this->login->open($pending['id'], $pending['email'], ['recovery_code' => 'true']);
        $this->logger->warning('platform admin recovery code used', ['email' => $pending['email']]);

        return AdminMfaAttempt::success();
    }
}
