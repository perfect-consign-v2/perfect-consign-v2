<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Root;

use PerfectApp\Routing\Route;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\EmailSignIn;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\SignInDirectory;
use PerfectConsign\Bootstrap\SignInLocked;
use PerfectConsign\Bootstrap\TenantLookup;
use PerfectConsign\Feature\Password\PasswordRecovery;
use PerfectConsign\Feature\Password\ResetNotice;

final class RootController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly Session $session,
        private readonly TenantLookup $tenants,
        private readonly EmailSignIn $emailSignIn,
        private readonly SignInDirectory $directory,
        private readonly PasswordRecovery $recovery,
        private readonly Actor $actor,
    ) {
    }

    #[Route('/', ['GET'])]
    public function show(): void
    {
        $slug = $this->signedInSlug();
        if ($slug !== null) {
            $this->app->redirect('/' . $slug . '/dashboard');
        }
        $this->loginPage(null);
    }

    #[Route('/', ['POST'])]
    public function submit(): void
    {
        $this->app->checkCsrf();
        $email = $this->app->request()->field('email');
        $password = $this->app->request()->raw('password');
        try {
            $signedIn = $this->emailSignIn->attempt($email, $password);
        } catch (SignInLocked) {
            $this->loginPage('Too many sign-in attempts. Try again in a few minutes.');

            return;
        }
        if (!$signedIn) {
            $this->loginPage('That email or password is wrong.');

            return;
        }
        $slug = $this->actor->slug();
        if ($slug === null) {
            $this->loginPage('That email or password is wrong.');

            return;
        }
        $this->app->redirect($this->actor->path($slug));
    }

    #[Route('/forgot', ['GET'])]
    public function forgotForm(): void
    {
        $this->forgotPage();
    }

    #[Route('/forgot', ['POST'])]
    public function forgotSubmit(): void
    {
        $this->app->checkCsrf();
        $email = $this->app->request()->field('email');
        $match = $this->directory->find($email);
        if ($match !== null) {
            $tenant = $this->tenants->find($match['slug']);
            if ($tenant !== null) {
                $this->recovery->request(
                    $tenant,
                    $match['username'],
                    $this->app->request()->origin
                );
            }
        }
        $this->app->redirect('/forgot', ResetNotice::SENT);
    }

    private function loginPage(?string $error): void
    {
        $this->app->show('Shared/Web/root', [
            'title' => 'Sign in',
            'displayName' => '',
            'slug' => '',
            'error' => $error,
            'signupEnabled' => false,
        ]);
    }

    private function forgotPage(): void
    {
        $this->app->show('Shared/Web/forgot', [
            'title' => 'Forgot password',
            'displayName' => '',
            'slug' => '',
            'error' => null,
        ]);
    }

    private function signedInSlug(): ?string
    {
        $slug = $this->session->get('owner_slug');
        if (!is_string($slug) || $slug === '' || $this->tenants->find($slug) === null) {
            return null;
        }

        return $slug;
    }
}
