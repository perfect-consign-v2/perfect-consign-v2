<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Staff;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;

final class StaffController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly StaffStore $staff,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/staff', ['GET'])]
    public function show(string $slug): void
    {
        $this->render($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/staff', ['POST'])]
    public function add(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $request = $this->app->request();
        try {
            $this->staff->addCashier(
                $request->field('username'),
                $request->field('display_name'),
                $request->raw('password'),
            );
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->render($slug, $error->getMessage());

            return;
        }
        $this->app->redirect('/' . $slug . '/staff', 'Cashier added.');
    }

    private function render(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Staff/staff', [
            'title' => 'Cashiers',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'cashiers' => $this->staff->cashiers(),
            'error' => $error,
        ]);
    }
}
