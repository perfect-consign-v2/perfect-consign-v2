<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Bootstrap\SignInLocked;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaChallenge;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaEnrollment;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaPending;
use PerfectConsign\Feature\Admin\Mfa\SetupCode;

final class AdminMfaController
{
    public function __construct(
        private readonly AdminApp $app,
        private readonly AdminMfaPending $pending,
        private readonly AdminMfaEnrollment $enrollment,
        private readonly AdminMfaChallenge $challenge,
        private readonly SetupCode $setup,
    ) {
    }

    #[AdminRoute('/mfa/enroll', ['GET'])]
    public function enrollForm(): void
    {
        $this->requireKind('/mfa/enroll');
        $pending = $this->pending->enrollment(time());
        if ($pending === null) {
            $this->app->redirect($this->app->url('/login'), 'That sign-in expired. Sign in again.');
        }
        $this->showEnroll($pending['secret'], $pending['grouped'], $pending['email'], null);
    }

    #[AdminRoute('/mfa/enroll', ['POST'])]
    public function enrollSubmit(): void
    {
        $this->app->checkCsrf();
        $this->requireKind('/mfa/enroll');
        $pending = $this->pending->enrollment(time());
        if ($pending === null) {
            $this->app->redirect($this->app->url('/login'), 'That sign-in expired. Sign in again.');
        }
        try {
            $attempt = $this->enrollment->confirm($this->app->request()->field('code'), time());
        } catch (SignInLocked) {
            $this->showEnroll($pending['secret'], $pending['grouped'], $pending['email'], 'Too many sign-in attempts. Try again in a few minutes.');

            return;
        }
        if (!$attempt->opened()) {
            $this->showEnroll($pending['secret'], $pending['grouped'], $pending['email'], $attempt->message());

            return;
        }
        $this->app->redirect($this->app->url('/mfa/codes'));
    }

    #[AdminRoute('/mfa/codes', ['GET'])]
    public function codesForm(): void
    {
        $this->requireKind('/mfa/codes');
        $codes = $this->pending->codes();
        if ($codes === null) {
            $this->app->session()->clear();
            $this->app->redirect($this->app->url('/login'), 'That sign-in expired. Sign in again.');
        }
        $this->showCodes($codes, null);
    }

    #[AdminRoute('/mfa/codes', ['POST'])]
    public function codesSubmit(): void
    {
        $this->app->checkCsrf();
        $this->requireKind('/mfa/codes');
        $codes = $this->pending->codes();
        $attempt = $this->enrollment->accept(time());
        if (!$attempt->opened()) {
            if ($codes === null) {
                $this->app->session()->clear();
                $this->app->redirect($this->app->url('/login'), $attempt->message());
            }
            $this->showCodes($codes, $attempt->message());

            return;
        }
        $this->app->redirect($this->app->url('/tenants'));
    }

    #[AdminRoute('/mfa', ['GET'])]
    public function challengeForm(): void
    {
        $this->requireKind('/mfa');
        $this->showChallenge(null);
    }

    #[AdminRoute('/mfa', ['POST'])]
    public function challengeSubmit(): void
    {
        $this->app->checkCsrf();
        $this->requireKind('/mfa');
        try {
            $attempt = $this->challenge->submit(
                $this->app->request()->field('code'),
                $this->app->request()->field('recovery_code'),
                time(),
            );
        } catch (SignInLocked) {
            $this->showChallenge('Too many sign-in attempts. Try again in a few minutes.');

            return;
        }
        if (!$attempt->opened()) {
            $this->showChallenge($attempt->message());

            return;
        }
        $this->app->redirect($this->app->url('/tenants'));
    }

    private function requireKind(string $target): void
    {
        if ($this->app->session()->current() !== null) {
            $this->app->redirect($this->app->url('/tenants'));
        }
        $pending = $this->app->session()->pendingTarget(time());
        if ($pending !== $target) {
            $this->app->redirect($this->app->url($pending ?? '/login'));
        }
    }

    private function showEnroll(string $secret, string $grouped, string $email, ?string $error): void
    {
        $this->app->showGuest('Feature/Admin/mfa-enroll', [
            'title' => 'Authenticator',
            'secret' => $grouped,
            'qr' => $this->setup->svg($this->setup->uri($email, $secret)),
            'email' => $email,
            'error' => $error,
        ]);
    }

    /**
     * @param list<string> $codes
     */
    private function showCodes(array $codes, ?string $error): void
    {
        $this->app->showGuest('Feature/Admin/mfa-codes', [
            'title' => 'Recovery codes',
            'codes' => $codes,
            'error' => $error,
        ]);
    }

    private function showChallenge(?string $error): void
    {
        $this->app->showGuest('Feature/Admin/mfa-challenge', [
            'title' => 'Authenticator',
            'error' => $error,
        ]);
    }
}
