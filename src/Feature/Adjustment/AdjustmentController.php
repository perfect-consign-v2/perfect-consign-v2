<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Adjustment;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;

final class AdjustmentController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly LedgerStore $ledger,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/adjustment', ['GET'])]
    public function form(string $slug): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Adjustment/adjustment', [
            'title' => 'Adjustment',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'accounts' => $this->ledger->postableAccounts(true),
        ]);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/adjustment', ['POST'])]
    public function submit(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        $reference = $this->app->request()->field('source_reference');
        try {
            if ($reference === '') {
                throw new DatabaseRefusal('a source reference is required');
            }
            $journalId = $this->ledger->post(new JournalDraft(
                postingKey: 'adjustment:' . $reference,
                postingDate: $this->app->request()->field('posting_date'),
                source: new JournalSource('adjustment', $reference, $this->app->request()->field('description')),
                lines: PostedLines::read($this->app->request(), 4, false),
            ));
        } catch (DatabaseRefusal $refusal) {
            $this->app->show('Feature/Adjustment/adjustment', [
                'title' => 'Adjustment',
                'displayName' => $tenant->displayName,
                'slug' => $tenant->slug,
                'accounts' => $this->ledger->postableAccounts(true),
                'error' => $this->app->catchRefusal($refusal),
            ]);
            return;
        } catch (\InvalidArgumentException $error) {
            $this->app->show('Feature/Adjustment/adjustment', [
                'title' => 'Adjustment',
                'displayName' => $tenant->displayName,
                'slug' => $tenant->slug,
                'accounts' => $this->ledger->postableAccounts(true),
                'error' => $error->getMessage(),
            ]);
            return;
        }
        $this->app->redirect('/' . $slug . '/journals/' . $journalId, 'Adjustment posted.');
    }
}
