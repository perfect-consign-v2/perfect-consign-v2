<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Check;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\SignedInPerson;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;

/**
 * Bounced check: find the check the store took by its number, then mark it
 * bounced. The database posts the check's own amount.
 */
final class NsfController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly ReceivedChecks $checks,
        private readonly MallSettings $mall,
        private readonly Actor $actor,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/checks/nsf', ['GET'])]
    public function show(string $slug): void
    {
        $this->app->open($slug, true);
        $this->page($slug, $this->app->request()->query('number'), null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/checks/nsf', ['POST'])]
    public function post(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $request = $this->app->request();
        $number = $request->field('number');
        try {
            SignedInPerson::require($this->actor->name(), $this->actor->role());
            $journalId = $request->field('journal_id');
            if (preg_match('/^[0-9a-fA-F-]{36}$/', $journalId) !== 1) {
                throw new DatabaseRefusal('Choose the check that bounced.');
            }
            $date = $request->field('posting_date');
            $posted = $this->checks->bounce($journalId, $date === '' ? $this->mall->today() : $date);
        } catch (DatabaseRefusal $refusal) {
            $this->page($slug, $number, $this->app->catchRefusal($refusal));

            return;
        }
        $this->app->redirect('/' . $slug . '/journals/' . $posted, 'Bounced check posted.');
    }

    private function page(string $slug, string $number, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Check/nsf', [
            'title' => 'Bounced check',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'error' => $error,
            'number' => $number,
            'found' => $this->checks->find($number),
        ]);
    }
}
