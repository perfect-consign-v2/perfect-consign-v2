<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Signup;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Shared\Http\Request;
use RuntimeException;

final class SignupController
{
    private const LINK_REFUSED = 'That link is invalid or expired. Start again at Create your store.';

    public function __construct(
        private readonly MallApp $app,
        private readonly PlatformSettings $settings,
        private readonly StoreSignup $signup,
    ) {
    }

    #[Route('/signup', ['GET'])]
    public function show(): void
    {
        if (!$this->settings->storeSignupEnabled()) {
            $this->disabled();

            return;
        }
        $this->form(null);
    }

    #[Route('/signup', ['POST'])]
    public function create(): void
    {
        $this->app->checkCsrf();
        $request = $this->app->request();
        try {
            $open = $this->signup->request(
                $request->field('store_name'),
                $request->field('email'),
                $request->field('password'),
                $request->field('password_confirm'),
                $request->address,
                $request->field('proof_seed'),
                $request->field('proof_nonce'),
                $request->origin,
            );
        } catch (RuntimeException $error) {
            $this->form($error->getMessage(), $request);

            return;
        }
        if (!$open) {
            $this->disabled();

            return;
        }
        $this->app->show('Feature/Signup/sent', [
            'title' => 'Check your email',
            'displayName' => '',
            'slug' => '',
            'sentence' => StoreSignup::SENT,
        ]);
    }

    #[Route('/signup/verify/([A-Za-z0-9_-]{43})', ['GET'])]
    public function verify(string $token): void
    {
        $state = $this->signup->state($token);
        if ($state === null || in_array($state['status'], ['expired', 'discarded'], true)) {
            $this->app->redirect('/signup', null, self::LINK_REFUSED);
        }
        if ($state['status'] === 'ready') {
            $this->app->redirect(
                '/?email=' . rawurlencode($state['email']),
                'Your store is ready. Sign in with your email and the password you chose.'
            );
        }
        if ($state['status'] === 'pending') {
            $this->app->show('Feature/Signup/verify', [
                'title' => 'Finish setting up your store',
                'displayName' => '',
                'slug' => '',
                'token' => $token,
                'email' => $state['email'],
            ]);

            return;
        }
        $this->app->show('Feature/Signup/wait', [
            'title' => 'Setting up your store',
            'displayName' => '',
            'slug' => '',
            'refreshSeconds' => 5,
            'slow' => $state['status'] === 'failed',
        ]);
    }

    #[Route('/signup/verify/([A-Za-z0-9_-]{43})', ['POST'])]
    public function confirm(string $token): void
    {
        $this->app->checkCsrf();
        $status = $this->signup->verify($token);
        if ($status === null || in_array($status, ['expired', 'discarded'], true)) {
            $this->app->redirect('/signup', null, self::LINK_REFUSED);
        }
        $this->app->redirect('/signup/verify/' . $token);
    }

    private function form(?string $error, ?Request $posted = null): void
    {
        $request = $this->app->request();
        $this->app->show('Feature/Signup/signup', [
            'title' => 'Create your store',
            'displayName' => '',
            'slug' => '',
            'error' => $error,
            'enabled' => true,
            'proof' => $this->signup->challenge($request->address),
            'storeName' => $posted?->field('store_name') ?? '',
            'email' => $posted?->field('email') ?? '',
            'password' => $posted?->field('password') ?? '',
            'passwordConfirm' => $posted?->field('password_confirm') ?? '',
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
        ]);
    }
}
