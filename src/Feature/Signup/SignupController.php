<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Signup;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\OwnerGate;
use RuntimeException;

final class SignupController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PlatformSettings $settings,
        private readonly StoreSignup $signup,
        private readonly OwnerEmailVerification $verification,
        private readonly OwnerGate $owners,
    ) {
    }

    #[Route('/signup', ['GET'])]
    public function show(): void
    {
        if (!$this->settings->storeSignupEnabled()) {
            $this->disabled();

            return;
        }
        $this->form(null, false);
    }

    #[Route('/signup', ['POST'])]
    public function create(): void
    {
        $this->app->checkCsrf();
        if (!$this->settings->storeSignupEnabled()) {
            $this->disabled();

            return;
        }
        $request = $this->app->request();
        try {
            $created = $this->signup->registerPublic(
                $request->field('store_name'),
                $request->field('email'),
                $request->field('password'),
                $request->field('password_confirm'),
                $request->address,
            );
        } catch (RuntimeException $error) {
            $this->form($error->getMessage(), false);

            return;
        }
        if (!$created['verified']) {
            $ownerId = $this->signup->ownerIdForSlug($created['slug']);
            if ($ownerId === null) {
                $this->form('That store could not be created.', false);

                return;
            }
            try {
                $this->verification->send($ownerId, $created['email'], $request->origin);
            } catch (RuntimeException $error) {
                $this->signup->abandon($created['slug']);
                $this->form($error->getMessage(), false);

                return;
            }
            $this->form(null, true);

            return;
        }
        $this->owners->signIn($created['slug'], $created['username'], $request->field('password'));
        $this->app->redirect('/' . $created['slug'] . '/', 'Your store is ready.');
    }

    #[Route('/signup/verify/([A-Za-z0-9_-]{43})', ['GET'])]
    public function verify(string $token): void
    {
        $confirmed = $this->verification->confirm($token);
        if ($confirmed === null) {
            $this->app->redirect('/', null, 'That confirmation link is invalid or expired.');
        }
        $this->app->redirect(
            '/?email=' . rawurlencode($confirmed['email']),
            'Email confirmed. Sign in to open your store.'
        );
    }

    private function form(?string $error, bool $checkEmail): void
    {
        $this->app->show('Feature/Signup/signup', [
            'title' => 'Create your store',
            'displayName' => '',
            'slug' => '',
            'error' => $error,
            'enabled' => true,
            'checkEmail' => $checkEmail,
        ]);
    }

    private function disabled(): void
    {
        $this->app->show('Feature/Signup/signup', [
            'title' => 'Create your store',
            'displayName' => '',
            'slug' => '',
            'error' => null,
            'enabled' => false,
            'checkEmail' => false,
        ]);
    }
}
