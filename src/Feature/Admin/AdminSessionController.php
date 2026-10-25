<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectApp\Routing\Route;
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

    #[Route('/admin', ['GET'])]
    public function home(): void
    {
        $this->app->requireAdmin();
        $this->app->redirect('/admin/tenants');
    }

    #[Route('/admin/login', ['GET'])]
    public function form(): void
    {
        if ($this->app->session()->current() !== null) {
            $this->app->redirect('/admin/tenants');
        }
        $this->app->showGuest('Feature/Admin/login', [
            'title' => 'Platform admin',
            'error' => null,
        ]);
    }

    #[Route('/admin/login', ['POST'])]
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
        if (!$signedIn) {
            $this->app->showGuest('Feature/Admin/login', [
                'title' => 'Platform admin',
                'error' => 'That email or password is wrong.',
            ]);

            return;
        }
        $this->app->redirect('/admin/tenants');
    }

    #[Route('/admin/logout', ['POST'])]
    public function logout(): void
    {
        $this->app->checkCsrf();
        $this->tenants->clear();
        $this->app->session()->clear();
        $this->app->redirect('/admin/login', 'Signed out.');
    }
}
