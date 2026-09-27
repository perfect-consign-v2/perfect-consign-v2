<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Opening;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;

final class OpeningController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly LedgerStore $ledger,
        private readonly OpeningStore $openings,
        private readonly PartyStore $parties,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/opening', ['GET'])]
    public function form(string $slug): void
    {
        $this->render($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/opening', ['POST'])]
    public function submit(string $slug): void
    {
        $this->app->checkCsrf();
        $tenant = $this->app->open($slug, true);
        try {
            if (!$this->openings->openingAllowed()) {
                throw new DatabaseRefusal('an opening is available only while the book has no other journal');
            }
            $journalId = $this->ledger->post(
                'opening',
                $this->app->request()->field('posting_date'),
                'opening',
                'opening',
                'Opening balance',
                PostedLines::read($this->app->request(), 6, true)
            );
        } catch (DatabaseRefusal $refusal) {
            $this->render($slug, $this->app->catchRefusal($refusal));
            return;
        } catch (\InvalidArgumentException $error) {
            $this->render($slug, $error->getMessage());
            return;
        }
        $this->app->redirect('/' . $tenant->slug . '/journals/' . $journalId, 'Opening posted.');
    }

    private function render(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Opening/opening', [
            'title' => 'Opening balance',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'allowed' => $this->openings->openingAllowed(),
            'accounts' => $this->ledger->postableAccounts(false),
            'vendors' => $this->parties->vendors(),
            'booths' => $this->parties->booths(),
            'error' => $error,
        ]);
    }
}
