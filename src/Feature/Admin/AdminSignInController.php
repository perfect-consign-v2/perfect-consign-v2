<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

/**
 * Admin, Sign-ins: every sign-in attempt at the store and admin doors, newest
 * first, filtered by email, address, and outcome.
 */
final class AdminSignInController
{
    public function __construct(
        private readonly AdminApp $app,
        private readonly SignInAttempts $attempts,
    ) {
    }

    #[AdminRoute('/sign-ins', ['GET'])]
    public function index(): void
    {
        $this->app->requireAdmin();
        $request = $this->app->request();
        $email = $request->query('email');
        $address = $request->query('address');
        $outcome = $request->query('outcome');
        $this->app->show('Feature/Admin/sign-ins', [
            'title' => 'Sign-ins',
            'email' => $email,
            'address' => $address,
            'outcome' => array_key_exists($outcome, SignInAttempts::OUTCOMES) ? $outcome : '',
            'attempts' => $this->attempts->listed($email, $address, $outcome),
        ]);
    }
}
