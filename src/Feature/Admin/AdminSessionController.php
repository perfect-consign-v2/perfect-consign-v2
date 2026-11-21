<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Bootstrap\LoginSession;
use PerfectConsign\Bootstrap\SignInLocked;

final class AdminSessionController
{
    public function __construct(
        private readonly AdminApp $app,
        private readonly AdminSignIn $signIn,
        private readonly LoginSession $tenants,
    ) {
    }

    #[AdminRoute('/', ['GET'])]
    public function home(): void
    {
        $this->app->requireAdmin();
        $this->app->redirect($this->app->url('/tenants'));
    }

    #[AdminRoute('/login', ['GET'])]
    public function form(): void
    {
        if ($this->app->session()->current() !== null) {
            $this->app->redirect($this->app->url('/tenants'));
        }
        $pending = $this->app->session()->pendingTarget(time());
        if ($pending !== null) {
            $this->app->redirect($this->app->url($pending));
        }
        $this->app->showGuest('Feature/Admin/login', [
            'title' => 'Platform admin',
            'error' => null,
        ]);
    }

    #[AdminRoute('/login', ['POST'])]
    public function submit(): void
    {
        $this->app->checkCsrf();
        $email = $this->app->request()->field('email');
        $password = $this->app->request()->raw('password');
        try {
            $signedIn = $this->signIn->attempt($email, $password);
        } catch (SignInLocked) {
            $this->app->showGuest('Feature/Admin/login', [
                'title' => 'Platform admin',
                'error' => 'Too many sign-in attempts. Try again in a few minutes.',
            ]);

            return;
        }
        if (!$signedIn->accepted()) {
            $this->app->showGuest('Feature/Admin/login', [
                'title' => 'Platform admin',
                'error' => 'That email or password is wrong.',
            ]);

            return;
        }
        if ($signedIn->isSignedIn()) {
            $this->app->redirect($this->app->url('/tenants'));
        }
        $next = $signedIn->needsEnrollment() ? '/mfa/enroll' : '/mfa';
        $this->app->redirect($this->app->url($next));
    }

    #[AdminRoute('/logout', ['POST'])]
    public function logout(): void
    {
        $this->app->checkCsrf();
        $this->tenants->clear();
        $this->app->session()->clear();
        $this->app->redirect($this->app->url('/login'), 'Signed out.');
    }
}
