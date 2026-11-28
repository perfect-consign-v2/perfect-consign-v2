<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;

/**
 * Settings → Bad debts (owner).
 */
final class BadDebtRulesController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly BadDebtRules $rules,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/settings/bad-debts', ['GET'])]
    public function show(string $slug): void
    {
        $this->page($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/settings/bad-debts', ['POST'])]
    public function save(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $request = $this->app->request();
        try {
            $this->rules->save(
                $request->field('write_off_wait_days'),
                $request->field('deposit_covers_rent') === '1',
                $request->field('unclaimed_dormancy_days'),
                $tenant->id,
            );
        } catch (DatabaseRefusal | \InvalidArgumentException $error) {
            $this->page($slug, $this->app->catchRefusal($error));

            return;
        }
        $this->app->redirect('/' . $slug . '/settings/bad-debts', 'Saved.');
    }

    private function page(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Mall/bad-debts', [
            'title' => 'Bad debts and unclaimed money',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'settingsPart' => 'bad-debts',
            'rules' => $this->rules->current(),
            'error' => $error,
        ]);
    }
}
