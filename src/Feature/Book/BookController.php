<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Book;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;

final class BookController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly LedgerStore $ledger,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/book', ['GET'])]
    public function show(string $slug): void
    {
        $this->showBook($slug, null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/periods', ['POST'])]
    public function addPeriod(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $error = null;
        try {
            $this->ledger->addPeriod(
                $this->app->request()->field('starts_on'),
                $this->app->request()->field('ends_on')
            );
        } catch (DatabaseRefusal $refusal) {
            $error = $this->app->catchRefusal($refusal);
        }
        if ($error === null) {
            $this->app->redirect('/' . $slug . '/book', 'Journal posted.');
        }
        $this->showBook($slug, $error);
    }

    private function showBook(string $slug, ?string $error): void
    {
        $tenant = $this->app->open($slug, true);
        $this->app->show('Feature/Book/book', [
            'title' => 'Book',
            'displayName' => $tenant->displayName,
            'slug' => $tenant->slug,
            'accounts' => $this->ledger->accounts(),
            'periods' => $this->ledger->periods(),
            'error' => $error,
        ]);
    }
}
