<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Shared\Tenant\TenantRecord;

final class ResetController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly PasswordRecovery $recovery,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reset/([A-Za-z0-9_-]{43})', ['GET'])]
    public function form(string $slug, string $token): void
    {
        $tenant = $this->app->open($slug, false);
        $this->render($tenant, $token, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/reset/([A-Za-z0-9_-]{43})', ['POST'])]
    public function submit(string $slug, string $token): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, false);
        $request = $this->app->request();
        try {
            $this->recovery->redeem($tenant, $token, $request->raw('password'), $request->raw('confirm'));
        } catch (\InvalidArgumentException $error) {
            $this->render($tenant, $token, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/login', 'Password updated. Sign in.');
    }

    private function render(TenantRecord $tenant, string $token, ?string $error): void
    {
        $open = $this->recovery->available($tenant, $token);
        if (!$open && ($error === null || $error === '')) {
            $error = ResetNotice::EXPIRED;
        }
        $this->app->show('Feature/Password/reset', [
            'title' => 'Reset password',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'token' => $token,
            'open' => $open,
            'error' => $error,
        ]);
    }
}
