<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Feature\Password\ResetNotice;

final class AdminPasswordController
{
    public function __construct(
        private readonly AdminApp $app,
        private readonly AdminPasswordRecovery $recovery,
    ) {
    }

    #[AdminRoute('/forgot', ['GET'])]
    public function forgotForm(): void
    {
        $this->app->showGuest('Feature/Admin/forgot', [
            'title' => 'Forgot password',
            'error' => null,
        ]);
    }

    #[AdminRoute('/forgot', ['POST'])]
    public function forgotSubmit(): void
    {
        $this->app->checkCsrf();
        try {
            $this->recovery->request($this->app->request()->field('email'), $this->app->request()->origin);
        } catch (\RuntimeException $error) {
            if ($error->getMessage() === AdminPasswordRecovery::MAIL_FAILED) {
                $this->app->redirect($this->app->url('/forgot'), $error->getMessage());
            }
            throw $error;
        }
        $this->app->redirect($this->app->url('/forgot'), ResetNotice::SENT);
    }

    #[AdminRoute('/reset/([A-Za-z0-9_-]{43})', ['GET'])]
    public function resetForm(string $token): void
    {
        if (!$this->recovery->available($token)) {
            $this->app->showGuest('Feature/Admin/reset', [
                'title' => 'Reset password',
                'token' => $token,
                'error' => 'That reset link expired. Request a new one.',
                'expired' => true,
            ]);

            return;
        }
        $this->app->showGuest('Feature/Admin/reset', [
            'title' => 'Reset password',
            'token' => $token,
            'error' => null,
            'expired' => false,
        ]);
    }

    #[AdminRoute('/reset/([A-Za-z0-9_-]{43})', ['POST'])]
    public function resetSubmit(string $token): void
    {
        $this->app->checkCsrf();
        try {
            $this->recovery->redeem(
                $token,
                $this->app->request()->raw('password'),
                $this->app->request()->raw('confirm'),
            );
        } catch (\InvalidArgumentException $error) {
            $this->app->showGuest('Feature/Admin/reset', [
                'title' => 'Reset password',
                'token' => $token,
                'error' => $error->getMessage(),
                'expired' => !$this->recovery->available($token),
            ]);

            return;
        }
        $this->app->redirect($this->app->url('/login'), 'Password saved. Sign in.');
    }

    #[AdminRoute('/password', ['GET'])]
    public function changeForm(): void
    {
        $this->app->requireAdmin();
        $this->app->show('Feature/Admin/password', [
            'title' => 'Change password',
            'error' => null,
        ]);
    }

    #[AdminRoute('/password', ['POST'])]
    public function changeSubmit(): void
    {
        $this->app->checkCsrf();
        $admin = $this->app->requireAdmin();
        try {
            $this->recovery->change(
                $admin['id'],
                $this->app->request()->raw('current'),
                $this->app->request()->raw('password'),
                $this->app->request()->raw('confirm'),
            );
        } catch (\InvalidArgumentException $error) {
            $this->app->show('Feature/Admin/password', [
                'title' => 'Change password',
                'error' => $error->getMessage(),
            ]);

            return;
        }
        $this->app->endSurface();
        $this->app->redirect($this->app->url('/login'), 'Password saved. Sign in with your new password.');
    }
}
